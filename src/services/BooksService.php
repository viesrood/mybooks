<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\BookRecord;
use yii\base\Component;

/**
 * Reading books, and writing the hand-entered ones.
 *
 * Everything here reads the local table only. A reader's books are loaded
 * once per request, with their covers eager loaded, so a team page with many
 * readers costs two queries per reader and no HTTP at all.
 */
class BooksService extends Component
{
    /** @var array<int, Book[]> */
    private array $booksByReader = [];

    /**
     * @return Book[] Grouped per shelf (want, reading, read), then by sort order.
     */
    public function getBooks(Reader $reader, ?Shelf $shelf = null, ?int $limit = null): array
    {
        if ($reader->id === null) {
            return [];
        }

        $books = $this->loadReader($reader->id);

        if ($shelf !== null) {
            $books = array_values(array_filter(
                $books,
                static fn(Book $book): bool => $book->getShelf() === $shelf,
            ));
        }

        if ($limit !== null && $limit >= 0) {
            $books = array_slice($books, 0, $limit);
        }

        return $books;
    }

    public function getBookById(int $id): ?Book
    {
        /** @var BookRecord|null $record */
        $record = BookRecord::findOne($id);

        return $record !== null ? Book::fromRecord($record) : null;
    }

    public function saveManualBook(Book $book, bool $runValidation = true): bool
    {
        $book->provider = \viesrood\mybooks\providers\Manual::handle();

        if ($runValidation && !$book->validate()) {
            return false;
        }

        $record = $book->id !== null ? BookRecord::findOne($book->id) : null;

        if ($record === null) {
            $record = new BookRecord();
            $record->readerId = (int)$book->readerId;
            $record->provider = $book->provider;
            $record->externalId = StringHelper::UUID();
            $record->sortOrder = $this->nextSortOrder((int)$book->readerId);
        }

        // An editor removed or replaced a cover we downloaded: that asset is
        // ours to clean up, and the same cover must not be fetched again.
        $ownedAssetId = $record->coverSourceUrl !== null && $record->coverAssetId !== null ? (int)$record->coverAssetId : null;
        $discardAssetId = null;

        if ($ownedAssetId !== null && $ownedAssetId !== $book->coverAssetId) {
            $discardAssetId = $ownedAssetId;

            if ($book->coverAssetId === null && $book->coverUrl === $record->coverSourceUrl) {
                $book->coverUrl = null;
            }
        }

        $record->shelf = $book->getShelf()->value;
        $record->title = $book->title;
        $record->subtitle = $book->subtitle ?: null;
        $record->authors = Json::encode(array_values($book->authors));
        $record->isbn = $book->isbn ?: null;
        $record->url = $book->url ?: null;
        $record->coverUrl = $book->coverUrl ?: null;
        if (($record->coverAssetId !== null ? (int)$record->coverAssetId : null) !== $book->coverAssetId) {
            // Picked (or cleared) by an editor: no longer a cover we downloaded.
            $record->coverSourceUrl = null;
        }

        $record->coverAssetId = $book->coverAssetId;
        $record->progress = $book->getShelf() === Shelf::Reading ? $book->progress : null;
        $record->rating = $book->rating ?: null;
        $record->startedAt = $book->startedAt ?: null;
        $record->finishedAt = $book->finishedAt ?: null;
        $record->addedAt = $book->addedAt ?? (new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');

        if (!$record->save(false)) {
            return false;
        }

        $book->id = (int)$record->id;
        $book->uid = $record->uid;
        $book->externalId = $record->externalId;
        $book->coverSourceUrl = $record->coverSourceUrl;
        $this->forget((int)$book->readerId);

        if ($discardAssetId !== null) {
            Plugin::getInstance()?->getCovers()->deleteAsset($discardAssetId);
        }

        // A cover picked by ISBN lookup arrives as a URL; store it locally
        // when a cover volume is configured.
        $ownCoverChanged = $book->coverSourceUrl !== null && $book->coverSourceUrl !== $book->coverUrl;

        if ($book->coverUrl !== null && ($book->coverAssetId === null || $ownCoverChanged)) {
            Plugin::getInstance()?->getCovers()->downloadForBook($book);
        }

        return true;
    }

    public function deleteBook(Book $book): bool
    {
        if ($book->id === null) {
            return false;
        }

        // Only covers the plugin downloaded itself are removed; a cover an
        // editor picked from the asset library stays where it is.
        if ($book->coverSourceUrl !== null) {
            Plugin::getInstance()?->getCovers()->deleteCover($book);
        }

        Db::delete(BookRecord::TABLE, ['id' => $book->id]);
        $this->forget((int)$book->readerId);

        return true;
    }

    /**
     * @param array<int|string> $ids Book ids in their new order.
     */
    public function reorder(int $readerId, array $ids): void
    {
        foreach (array_values($ids) as $order => $id) {
            Db::update(BookRecord::TABLE, ['sortOrder' => $order], ['id' => (int)$id, 'readerId' => $readerId]);
        }

        $this->forget($readerId);
    }

    public function forget(?int $readerId = null): void
    {
        if ($readerId === null) {
            $this->booksByReader = [];
        } else {
            unset($this->booksByReader[$readerId]);
        }
    }

    /**
     * @return Book[]
     */
    private function loadReader(int $readerId): array
    {
        if (isset($this->booksByReader[$readerId])) {
            return $this->booksByReader[$readerId];
        }

        if (!Craft::$app->getDb()->tableExists(BookRecord::TABLE)) {
            return $this->booksByReader[$readerId] = [];
        }

        /** @var BookRecord[] $records */
        $records = BookRecord::find()
            ->where(['readerId' => $readerId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $books = array_map(static fn(BookRecord $record): Book => Book::fromRecord($record), $records);

        $order = array_flip(array_map(static fn(Shelf $shelf): string => $shelf->value, Shelf::cases()));
        usort($books, static fn(Book $a, Book $b): int =>
            [$order[$a->getShelf()->value], $a->sortOrder, $a->id] <=> [$order[$b->getShelf()->value], $b->sortOrder, $b->id]);

        $this->eagerLoadCovers($books);

        return $this->booksByReader[$readerId] = $books;
    }

    /**
     * @param Book[] $books
     */
    private function eagerLoadCovers(array $books): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(Book $book): ?int => $book->coverAssetId,
            $books,
        ))));

        $assets = [];

        if ($ids !== []) {
            /** @var Asset[] $found */
            $found = Asset::find()->id($ids)->status(null)->all();

            foreach ($found as $asset) {
                $assets[(int)$asset->id] = $asset;
            }
        }

        foreach ($books as $book) {
            $book->setCover($book->coverAssetId !== null ? ($assets[$book->coverAssetId] ?? null) : null);
        }
    }

    private function nextSortOrder(int $readerId): int
    {
        $max = BookRecord::find()->where(['readerId' => $readerId])->max('sortOrder');

        return is_numeric($max) ? (int)$max + 1 : 0;
    }
}
