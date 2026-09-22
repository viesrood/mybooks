<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\records\ReaderRecord;
use yii\base\Component;

/**
 * Readers are stored in project config (they are settings: which account,
 * which service), and mirrored into the mybooks_readers table by the handlers
 * below so books can reference them with a foreign key.
 */
class ReadersService extends Component
{
    public const CONFIG_KEY = 'mybooks.readers';

    /** @var Reader[]|null */
    private ?array $readers = null;

    /**
     * @return Reader[] In sort order.
     */
    public function getAllReaders(): array
    {
        if ($this->readers === null) {
            $this->readers = [];

            if (!Craft::$app->getDb()->tableExists(ReaderRecord::TABLE)) {
                return $this->readers;
            }

            /** @var ReaderRecord[] $records */
            $records = ReaderRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all();

            foreach ($records as $record) {
                $this->readers[] = Reader::fromRecord($record);
            }
        }

        return $this->readers;
    }

    public function getReaderById(int $id): ?Reader
    {
        foreach ($this->getAllReaders() as $reader) {
            if ($reader->id === $id) {
                return $reader;
            }
        }

        return null;
    }

    public function getReaderByUid(string $uid): ?Reader
    {
        foreach ($this->getAllReaders() as $reader) {
            if ($reader->uid === $uid) {
                return $reader;
            }
        }

        return null;
    }

    public function getReaderByHandle(string $handle): ?Reader
    {
        foreach ($this->getAllReaders() as $reader) {
            if (strcasecmp($reader->handle, $handle) === 0) {
                return $reader;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getReaderOptions(): array
    {
        return array_map(
            static fn(Reader $reader): array => ['label' => $reader->name, 'value' => (string)$reader->uid],
            $this->getAllReaders(),
        );
    }

    public function saveReader(Reader $reader, bool $runValidation = true): bool
    {
        $isNew = $reader->uid === null;

        if ($isNew) {
            $reader->uid = StringHelper::UUID();
            $reader->sortOrder = count($this->getAllReaders());
        }

        if ($runValidation && !$reader->validate()) {
            if ($isNew) {
                $reader->uid = null;
            }

            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $reader->uid,
            $reader->getConfig(),
            sprintf('Save My Books reader “%s”', $reader->handle),
        );

        $reader->id = Db::idByUid(ReaderRecord::TABLE, (string)$reader->uid);

        return true;
    }

    public function deleteReader(Reader $reader): bool
    {
        if ($reader->uid === null) {
            return false;
        }

        // Covers downloaded for this reader go with it.
        if ($reader->id !== null) {
            \viesrood\mybooks\Plugin::getInstance()?->getCovers()->deleteCoversOfReader($reader->id);
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $reader->uid,
            sprintf('Delete My Books reader “%s”', $reader->handle),
        );

        return true;
    }

    /**
     * @param string[] $ids Reader ids in their new order.
     */
    public function reorderReaders(array $ids): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach (array_values($ids) as $order => $id) {
            $reader = $this->getReaderById((int)$id);

            if ($reader?->uid !== null) {
                $projectConfig->set(self::CONFIG_KEY . '.' . $reader->uid . '.sortOrder', $order, 'Reorder My Books readers');
            }
        }
    }

    public function handleChangedReader(ConfigEvent $event): void
    {
        $uid = (string)$event->tokenMatches[0];
        /** @var array<string, mixed> $data */
        $data = $event->newValue;

        /** @var ReaderRecord|null $record */
        $record = ReaderRecord::findOne(['uid' => $uid]);

        if ($record === null) {
            $record = new ReaderRecord();
            $record->uid = $uid;
        }

        $shelves = $data['shelves'] ?? [];

        $record->name = (string)($data['name'] ?? '');
        $record->handle = (string)($data['handle'] ?? '');
        $record->provider = (string)($data['provider'] ?? '');
        $record->account = isset($data['account']) ? (string)$data['account'] : null;
        $record->token = isset($data['token']) ? (string)$data['token'] : null;
        $record->shelves = is_array($shelves) ? implode(',', array_map('strval', $shelves)) : (string)$shelves;
        $record->sortOrder = (int)($data['sortOrder'] ?? 0);
        $record->save(false);

        $this->readers = null;
    }

    public function handleDeletedReader(ConfigEvent $event): void
    {
        $uid = (string)$event->tokenMatches[0];

        // Books follow through the foreign key's ON DELETE CASCADE.
        Db::delete(ReaderRecord::TABLE, ['uid' => $uid]);

        $this->readers = null;
    }

    public function handleRebuild(RebuildConfigEvent $event): void
    {
        $readers = [];

        foreach ($this->getAllReaders() as $reader) {
            $readers[(string)$reader->uid] = $reader->getConfig();
        }

        $event->config['mybooks']['readers'] = $readers;
    }

    /**
     * Stores the outcome of a sync. Runtime state, so straight to the
     * database and never into project config.
     */
    public function recordSync(Reader $reader, ?string $error): void
    {
        if ($reader->id === null) {
            return;
        }

        $now = new \DateTime();

        Db::update(ReaderRecord::TABLE, [
            'lastSyncedAt' => Db::prepareDateForDb($now),
            'lastError' => $error !== null ? mb_substr($error, 0, 2000) : null,
        ], ['id' => $reader->id]);

        $reader->lastSyncedAt = $now;
        $reader->lastError = $error;
        $this->readers = null;
    }

    /**
     * Book counts per reader and shelf, for overview screens.
     *
     * @return array<int, array<string, int>>
     */
    public function getBookCounts(): array
    {
        $rows = (new Query())
            ->select(['readerId', 'shelf', 'count' => 'COUNT(*)'])
            ->from(\viesrood\mybooks\records\BookRecord::TABLE)
            ->groupBy(['readerId', 'shelf'])
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int)$row['readerId']][(string)$row['shelf']] = (int)$row['count'];
        }

        return $counts;
    }
}
