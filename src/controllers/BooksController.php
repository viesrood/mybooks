<?php

declare(strict_types=1);

namespace viesrood\mybooks\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\Controller;
use viesrood\mybooks\base\ProviderException;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\providers\OpenLibrary;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * A reader's books. Synced readers are read-only here; hand-entered readers
 * can add, edit, reorder and delete.
 */
class BooksController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(Plugin::PERMISSION_MANAGE_BOOKS)) {
            throw new ForbiddenHttpException('User is not permitted to perform this action.');
        }

        return true;
    }

    public function actionIndex(int $readerId): Response
    {
        $reader = $this->reader($readerId);

        return $this->renderTemplate('mybooks/books/_index', [
            'reader' => $reader,
            'books' => $reader->getBooks(),
            'editable' => $reader->isManual(),
        ]);
    }

    public function actionEdit(int $readerId, ?int $bookId = null, ?Book $book = null): Response
    {
        $reader = $this->reader($readerId);
        $this->requireManual($reader);

        if ($book === null) {
            $book = $bookId !== null ? $this->book($reader, $bookId) : new Book(['readerId' => $reader->id]);
        }

        return $this->renderTemplate('mybooks/books/_edit', [
            'reader' => $reader,
            'book' => $book,
            'isNew' => $book->id === null,
            'shelfOptions' => Shelf::options(),
            'coverVolume' => Plugin::getInstance()?->getCovers()->getVolume(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $reader = $this->reader((int)$this->request->getRequiredBodyParam('readerId'));
        $this->requireManual($reader);

        $bookId = $this->request->getBodyParam('bookId');
        $book = $bookId ? $this->book($reader, (int)$bookId) : new Book(['readerId' => $reader->id]);

        $book->title = (string)$this->request->getBodyParam('title', '');
        $book->subtitle = $this->request->getBodyParam('subtitle') ?: null;
        $book->authors = BookData::authors(preg_split('/\R/', (string)$this->request->getBodyParam('authors', '')) ?: []);
        $book->isbn = $this->request->getBodyParam('isbn') ?: null;
        $book->url = $this->request->getBodyParam('url') ?: null;
        $book->setShelf($this->request->getBodyParam('shelf'));
        $progress = $this->request->getBodyParam('progress');
        $book->progress = is_numeric($progress) ? (int)$progress : null;
        $rating = $this->request->getBodyParam('rating');
        $book->rating = is_numeric($rating) && (float)$rating > 0 ? (float)$rating : null;
        $book->startedAt = $this->date('startedAt');
        $book->finishedAt = $this->date('finishedAt');

        $coverIds = $this->request->getBodyParam('coverAssetId');
        $book->coverAssetId = is_array($coverIds) && isset($coverIds[0]) && is_numeric($coverIds[0]) ? (int)$coverIds[0] : null;
        // Only set by the ISBN lookup; validated again before any download.
        $book->coverUrl = BookData::httpUrl($this->request->getBodyParam('coverUrl'));

        if (!Plugin::getInstance()?->getBooks()->saveManualBook($book)) {
            return $this->asModelFailure($book, Craft::t('mybooks', 'Couldn’t save the book.'), 'book');
        }

        return $this->asModelSuccess($book, Craft::t('mybooks', 'Book saved.'), 'book', [], $reader->getCpBooksUrl());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $book = Plugin::getInstance()?->getBooks()->getBookById((int)$this->request->getRequiredBodyParam('id'));
        $reader = $book?->getReader();

        if ($book === null || $reader === null) {
            throw new NotFoundHttpException('Book not found');
        }

        $this->requireManual($reader);
        Plugin::getInstance()?->getBooks()->deleteBook($book);

        return $this->asSuccess();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        /** @var array<int|string> $ids */
        $ids = Json::decode((string)$this->request->getRequiredBodyParam('ids'));

        // The reader follows from the books; reorder() only touches ids that
        // belong to that reader, so a foreign id in the list is ignored.
        $first = Plugin::getInstance()?->getBooks()->getBookById((int)($ids[0] ?? 0));
        $reader = $first?->getReader();

        if ($reader === null) {
            throw new NotFoundHttpException('Book not found');
        }

        $this->requireManual($reader);
        Plugin::getInstance()?->getBooks()->reorder((int)$reader->id, $ids);

        return $this->asSuccess();
    }

    /**
     * Looks up title, authors and cover by ISBN at Open Library, to fill in
     * the form. Nothing is saved.
     */
    public function actionLookupIsbn(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $isbn = BookData::isbn($this->request->getRequiredBodyParam('isbn'));

        if ($isbn === null) {
            return $this->asFailure(Craft::t('mybooks', 'An ISBN has 10 or 13 digits.'));
        }

        try {
            $data = (new OpenLibrary())->lookupIsbn($isbn);
        } catch (ProviderException $e) {
            return $this->asFailure($e->getMessage());
        }

        if ($data === null) {
            return $this->asFailure(Craft::t('mybooks', 'Open Library does not know this ISBN.'));
        }

        return $this->asSuccess(data: [
            'title' => $data->title,
            'subtitle' => $data->subtitle,
            'authors' => $data->authors,
            'url' => $data->url,
            'coverUrl' => $data->coverUrl,
            'isbn' => $isbn,
        ]);
    }

    private function date(string $name): ?string
    {
        $value = $this->request->getBodyParam($name);

        if ($value === null || $value === '' || (is_array($value) && ($value['date'] ?? '') === '')) {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value, true);

        return $date !== false ? $date->format('Y-m-d') : null;
    }

    private function reader(int $readerId): Reader
    {
        $reader = Plugin::getInstance()?->getReaders()->getReaderById($readerId);

        if ($reader === null) {
            throw new NotFoundHttpException('Reader not found');
        }

        return $reader;
    }

    private function book(Reader $reader, int $bookId): Book
    {
        $book = Plugin::getInstance()?->getBooks()->getBookById($bookId);

        if ($book === null || $book->readerId !== $reader->id) {
            throw new NotFoundHttpException('Book not found');
        }

        return $book;
    }

    private function requireManual(Reader $reader): void
    {
        if (!$reader->isManual()) {
            throw new BadRequestHttpException('The books of this reader come from a book service and are read-only.');
        }
    }
}
