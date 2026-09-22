<?php

declare(strict_types=1);

namespace viesrood\mybooks\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\jobs\StoreCovers;
use viesrood\mybooks\jobs\SyncAccount;
use viesrood\mybooks\models\Library;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\CoverRecord;
use yii\db\Schema;

/**
 * Books on an element: picked by hand (search Open Library by title or
 * ISBN), or synced from a linked Open Library account.
 *
 * {% for book in entry.books.shelf('reading', 4) %}
 *     {{ book.title }}, {{ book.authorsString }}
 * {% endfor %}
 */
class BooksField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('mybooks', 'Books');
    }

    public static function icon(): string
    {
        return 'book';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s', Library::class);
    }

    public static function dbType(): string
    {
        return Schema::TYPE_JSON;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof Library) {
            return $value;
        }

        return Library::fromFieldData($value);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!$value instanceof Library || ($value->manualBooks === [] && $value->account === '')) {
            return null;
        }

        return $value->toFieldData();
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof Library || $value->isEmpty();
    }

    /**
     * @return array<int, mixed>
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                fn(ElementInterface $element) => $this->validateLibrary($element),
                'skipOnEmpty' => false,
            ],
        ];
    }

    public function validateLibrary(ElementInterface $element): void
    {
        $value = $element->getFieldValue((string)$this->handle);

        if (!$value instanceof Library || $value->validate()) {
            return;
        }

        foreach ($value->getErrors() as $errors) {
            foreach ($errors as $error) {
                $element->addError((string)$this->handle, $error);
            }
        }
    }

    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        parent::afterElementSave($element, $isNew);

        if (ElementHelper::isDraftOrRevision($element) || $element->propagating) {
            return;
        }

        $value = $element->getFieldValue((string)$this->handle);
        $plugin = Plugin::getInstance();

        if (!$value instanceof Library || $plugin === null) {
            return;
        }

        $queue = Craft::$app->getQueue();

        // A newly linked account: fill its shelves now instead of at the next
        // cron run.
        if ($value->isLinked() && $plugin->getAccounts()->getAccount($value->account)?->lastSyncedAt === null) {
            $queue->push(new SyncAccount(['account' => $value->account]));
        }

        // Covers of hand-picked books that are not stored yet.
        if ($value->mode === Library::MODE_MANUAL && $plugin->getCovers()->isEnabled()) {
            $missing = $this->missingCovers($value);

            if ($missing !== []) {
                $queue->push(new StoreCovers(['covers' => $missing]));
            }
        }
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof Library || $value->isEmpty()) {
            return '';
        }

        if ($value->isLinked()) {
            return Html::encode('Open Library: ' . $value->account);
        }

        return Html::encode(Craft::t('mybooks', '{count, plural, =1{One book} other{# books}}', ['count' => count($value->manualBooks)]));
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof Library) {
            return '';
        }

        $words = [];

        foreach ($value->manualBooks as $book) {
            $words[] = $book->title;
            $words[] = implode(' ', $book->authors);
        }

        return implode(' ', $words);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $library = $value instanceof Library ? $value : new Library();
        $plugin = Plugin::getInstance();
        $status = $library->getAccountStatus();

        $state = $library->toFieldData();

        // Covers already stored locally, so the editor sees the same image the
        // site will show.
        $thumbs = [];

        if ($plugin !== null) {
            $plugin->getCovers()->eagerLoad($library->manualBooks);

            foreach ($library->manualBooks as $book) {
                $thumbs[(string)$book->id] = $book->getCover()?->getUrl(['width' => 80]) ?? $book->coverUrl;
            }
        }

        return Craft::$app->getView()->renderTemplate('mybooks/_field/input', [
            'field' => $this,
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'state' => $state,
            'thumbs' => $thumbs,
            'library' => $library,
            'status' => $status,
            'syncedCount' => $library->isLinked() ? $library->count() : 0,
            'shelfOptions' => Shelf::options(),
            'element' => $element,
        ]);
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        // The input posts its whole state as one JSON string.
        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return $this->normalizeValue($value, $element);
    }

    /**
     * @return array<string, string> Cover URL => title, for covers not stored
     *                               or rejected yet.
     */
    private function missingCovers(Library $library): array
    {
        $urls = [];

        foreach ($library->manualBooks as $book) {
            if ($book->coverUrl !== null) {
                $urls[$book->coverUrl] = $book->title;
            }
        }

        if ($urls === []) {
            return [];
        }

        $known = array_flip(CoverRecord::find()
            ->select(['urlHash'])
            ->where(['urlHash' => array_map([CoverRecord::class, 'hash'], array_keys($urls))])
            ->column());

        return array_filter(
            $urls,
            static fn(string $title, string $url): bool => !isset($known[CoverRecord::hash($url)]),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
