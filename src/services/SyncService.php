<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\events\SyncEvent;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\models\SyncOutcome;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\BookRecord;
use yii\base\Component;

/**
 * Copies a reader's shelves from their book service into the local table.
 *
 * Rules:
 * - a shelf that failed to load keeps its last good books;
 * - a book is only written when something about it changed (hash);
 * - only this provider's books are ever removed.
 */
class SyncService extends Component
{
    public const EVENT_AFTER_SYNC = 'afterSync';

    public function syncReader(Reader $reader): SyncOutcome
    {
        $outcome = new SyncOutcome();
        $provider = $reader->getProviderInstance();

        if ($provider === null || !$provider->supportsSync() || $reader->id === null) {
            $outcome->skipped = true;

            return $outcome;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin?->getSettings();
        $shelves = $reader->getShelves();
        $result = $provider->fetchShelves($reader, $shelves, $settings->maxBooksPerShelf ?? 50);
        $outcome->errors = $result->getErrors();

        /** @var array<string, BookRecord> $existing keyed by externalId */
        $existing = [];

        /** @var BookRecord[] $records */
        $records = BookRecord::find()->where(['readerId' => $reader->id, 'provider' => $provider::handle()])->all();

        foreach ($records as $record) {
            $existing[$record->externalId] = $record;
        }

        $seen = [];
        $fetched = $result->getBooks();

        foreach ($fetched as $shelf => $books) {
            foreach ($books as $position => $data) {
                $seen[$data->externalId] = true;
                $this->upsert($reader, $provider::handle(), $data, $position, $existing[$data->externalId] ?? null, $outcome);
            }
        }

        // Remove books that are no longer on any shelf that loaded, plus books
        // on shelves the reader no longer syncs. Shelves that failed keep theirs.
        $wanted = array_map(static fn(Shelf $shelf): string => $shelf->value, $shelves);

        foreach ($existing as $externalId => $record) {
            if (isset($seen[$externalId])) {
                continue;
            }

            $shelfLoaded = array_key_exists($record->shelf, $fetched);
            $shelfDropped = !in_array($record->shelf, $wanted, true);

            if ($shelfLoaded || $shelfDropped) {
                $this->remove($record);
                $outcome->removed++;
            }
        }

        // Books from a provider this reader no longer uses.
        $outcome->removed += $this->removeOtherProviders($reader, $provider::handle());

        $plugin?->getBooks()->forget($reader->id);
        $outcome->covers = $this->syncCovers($reader, $settings->maxCoverDownloadsPerSync ?? 40);
        $plugin?->getBooks()->forget($reader->id);

        $plugin?->getReaders()->recordSync($reader, $outcome->errorSummary());

        if ($this->hasEventHandlers(self::EVENT_AFTER_SYNC)) {
            $this->trigger(self::EVENT_AFTER_SYNC, new SyncEvent([
                'reader' => $reader,
                'changed' => $outcome->changed(),
                'errors' => $outcome->errors,
            ]));
        }

        return $outcome;
    }

    private function upsert(Reader $reader, string $provider, BookData $data, int $position, ?BookRecord $record, SyncOutcome $outcome): void
    {
        $hash = $data->hash();

        if ($record !== null && $record->hash === $hash && (int)$record->sortOrder === $position) {
            return;
        }

        $isNew = $record === null;

        if ($record === null) {
            $record = new BookRecord();
            $record->readerId = (int)$reader->id;
            $record->provider = $provider;
            $record->externalId = $data->externalId;
        }

        $record->shelf = $data->shelf->value;
        // A book without a title is still a book; show something sensible.
        $record->title = $data->title !== '' ? $data->title : Craft::t('mybooks', 'Untitled');
        $record->subtitle = $data->subtitle;
        $record->authors = Json::encode($data->authors);
        $record->isbn = $data->isbn;
        $record->url = $data->url;
        $record->coverUrl = $data->coverUrl;
        $record->progress = $data->progress;
        $record->rating = $data->rating;
        $record->startedAt = $data->startedAt;
        $record->finishedAt = $data->finishedAt;
        $record->addedAt = $data->addedAt;
        $record->sortOrder = $position;
        $record->hash = $hash;
        $record->save(false);

        // A moved book counts as a change too: the page shows a different order.
        if ($isNew) {
            $outcome->added++;
        } else {
            $outcome->updated++;
        }
    }

    private function remove(BookRecord $record): void
    {
        $book = Book::fromRecord($record);
        Plugin::getInstance()?->getCovers()->deleteCover($book);
        Db::delete(BookRecord::TABLE, ['id' => $record->id]);
    }

    private function removeOtherProviders(Reader $reader, string $provider): int
    {
        /** @var BookRecord[] $records */
        $records = BookRecord::find()
            ->where(['readerId' => $reader->id])
            ->andWhere(['not', ['provider' => $provider]])
            ->all();

        foreach ($records as $record) {
            $this->remove($record);
        }

        return count($records);
    }

    /**
     * Downloads missing or changed covers, at most $max per run.
     */
    private function syncCovers(Reader $reader, int $max): int
    {
        $covers = Plugin::getInstance()?->getCovers();

        if ($covers === null || !$covers->isEnabled() || $max <= 0) {
            return 0;
        }

        $changed = 0;
        $attempts = 0;

        // What a page shows first gets its cover first: a big "want to read"
        // shelf must not use up the budget before "currently reading".
        $books = [];

        foreach ([Shelf::Reading, Shelf::Read, Shelf::Want] as $shelf) {
            array_push($books, ...(Plugin::getInstance()?->getBooks()->getBooks($reader, $shelf) ?? []));
        }

        foreach ($books as $book) {
            // coverSourceUrl is the URL we last fetched (or gave up on), so a
            // new URL from the service is fetched and a known one is not.
            $needsDownload = $book->coverUrl !== null && $book->coverSourceUrl !== $book->coverUrl;
            $needsRemoval = $book->coverUrl === null && $book->coverAssetId !== null && $book->coverSourceUrl !== null;

            if (!$needsDownload && !$needsRemoval) {
                continue;
            }

            if ($needsDownload && $attempts >= $max) {
                continue;
            }

            if ($needsDownload) {
                $attempts++;
            }

            if ($covers->downloadForBook($book)) {
                $changed++;
            }
        }

        return $changed;
    }
}
