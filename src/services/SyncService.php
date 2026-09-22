<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\events\SyncEvent;
use viesrood\mybooks\models\Account;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\models\SyncOutcome;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\providers\OpenLibrary;
use viesrood\mybooks\records\BookRecord;
use yii\base\Component;

/**
 * Copies the shelves of linked Open Library accounts into the local table,
 * and stores covers locally.
 *
 * Rules:
 * - a shelf that failed to load keeps its last good books;
 * - a book is only written when something about it changed (hash);
 * - a quiet sync writes nothing and reports nothing.
 */
class SyncService extends Component
{
    public const EVENT_AFTER_SYNC = 'afterSync';

    private ?OpenLibrary $openLibrary = null;

    /**
     * Syncs every linked account, removes accounts nobody links to any more,
     * and deletes covers nothing uses.
     *
     * @return array<string, SyncOutcome> Keyed by account.
     */
    public function syncAll(): array
    {
        $plugin = Plugin::getInstance();
        $accounts = $plugin?->getAccounts();

        if ($plugin === null || $accounts === null) {
            return [];
        }

        $accounts->discover(true);
        $linked = $accounts->getLinkedAccounts();
        $outcomes = [];

        foreach ($linked as $account) {
            $outcomes[$account] = $this->syncAccount($account);
        }

        $accounts->removeUnused($linked);
        $this->storeManualCovers();
        $plugin->getCovers()->collectGarbage($this->coverUrlsInUse());

        return $outcomes;
    }

    /**
     * Stores covers of hand-picked books that are not stored yet (the save
     * of an element queues this too; this is the safety net).
     */
    public function storeManualCovers(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 0;
        }

        $urls = array_fill_keys($plugin->getAccounts()->getManualCoverUrls(), null);

        return $plugin->getCovers()->store($urls, $plugin->getSettings()->maxCoverDownloadsPerSync);
    }

    public function syncAccount(string $account): SyncOutcome
    {
        $outcome = new SyncOutcome();
        $plugin = Plugin::getInstance();

        if ($plugin === null || OpenLibrary::normalizeUsername($account) === '') {
            $outcome->skipped = true;

            return $outcome;
        }

        $settings = $plugin->getSettings();
        $status = $plugin->getAccounts()->ensureAccount($account);
        $shelves = $plugin->getAccounts()->getShelvesForAccount($account);
        $result = $this->openLibrary()->fetchShelves($account, $shelves, $settings->maxBooksPerShelf);
        $outcome->errors = $result->getErrors();

        /** @var array<string, BookRecord> $existing keyed by externalId */
        $existing = [];

        /** @var BookRecord[] $records */
        $records = BookRecord::find()->where(['accountId' => $status->id])->all();

        foreach ($records as $record) {
            $existing[$record->externalId] = $record;
        }

        $seen = [];
        $fetched = $result->getBooks();

        foreach ($fetched as $books) {
            foreach ($books as $position => $data) {
                $seen[$data->externalId] = true;
                $this->upsert($status, $data, $position, $existing[$data->externalId] ?? null, $outcome);
            }
        }

        // Books gone from a shelf that loaded are removed, and so are books
        // on shelves no field shows any more; shelves that failed keep theirs.
        $wanted = array_map(static fn(Shelf $shelf): string => $shelf->value, $shelves);

        foreach ($existing as $externalId => $record) {
            $dropped = !in_array($record->shelf, $wanted, true);

            if (!isset($seen[$externalId]) && ($dropped || array_key_exists($record->shelf, $fetched))) {
                Db::delete(BookRecord::TABLE, ['id' => $record->id]);
                $outcome->removed++;
            }
        }

        $plugin->getBooks()->forget($account);
        $outcome->covers = $this->storeCoversFor($account);
        $plugin->getAccounts()->recordSync($status, $outcome->errorSummary());

        if ($this->hasEventHandlers(self::EVENT_AFTER_SYNC)) {
            $this->trigger(self::EVENT_AFTER_SYNC, new SyncEvent([
                'account' => $account,
                'changed' => $outcome->changed(),
                'errors' => $outcome->errors,
                'elements' => $plugin->getAccounts()->getElementsForAccount($account),
            ]));
        }

        return $outcome;
    }

    public function setOpenLibrary(OpenLibrary $openLibrary): void
    {
        $this->openLibrary = $openLibrary;
    }

    private function openLibrary(): OpenLibrary
    {
        return $this->openLibrary ??= new OpenLibrary();
    }

    private function upsert(Account $account, BookData $data, int $position, ?BookRecord $record, SyncOutcome $outcome): void
    {
        $hash = $data->hash();

        if ($record !== null && $record->hash === $hash && (int)$record->sortOrder === $position) {
            return;
        }

        $isNew = $record === null;

        if ($record === null) {
            $record = new BookRecord();
            $record->accountId = (int)$account->id;
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

    /**
     * Stores missing covers of an account, what a page shows first first: a
     * big "want to read" shelf must not use up the budget before "currently
     * reading".
     */
    private function storeCoversFor(string $account): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null || !$plugin->getCovers()->isEnabled()) {
            return 0;
        }

        $urls = [];

        foreach ([Shelf::Reading, Shelf::Read, Shelf::Want] as $shelf) {
            foreach ($plugin->getBooks()->getSyncedBooks($account) as $book) {
                if ($book->getShelf() === $shelf && $book->coverUrl !== null) {
                    $urls[$book->coverUrl] ??= $book->title;
                }
            }
        }

        return $plugin->getCovers()->store($urls, $plugin->getSettings()->maxCoverDownloadsPerSync);
    }

    /**
     * @return string[]
     */
    private function coverUrlsInUse(): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return [];
        }

        $synced = BookRecord::find()->select(['coverUrl'])->where(['not', ['coverUrl' => null]])->column();

        return array_values(array_unique(array_merge($synced, $plugin->getAccounts()->getManualCoverUrls())));
    }
}
