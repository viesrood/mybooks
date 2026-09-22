<?php

declare(strict_types=1);

namespace viesrood\mybooks\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use viesrood\mybooks\records\BookRecord;
use viesrood\mybooks\records\ReaderRecord;
use viesrood\mybooks\services\ReadersService;

/**
 * Creates the readers and books tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(ReaderRecord::TABLE)) {
            $this->createTable(ReaderRecord::TABLE, [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string(64)->notNull(),
                'provider' => $this->string(32)->notNull(),
                'account' => $this->string(),
                'token' => $this->string(),
                'shelves' => $this->string(),
                'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
                // Runtime state: deliberately not in project config.
                'lastSyncedAt' => $this->dateTime(),
                'lastError' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, ReaderRecord::TABLE, ['handle'], true);
            $this->createIndex(null, ReaderRecord::TABLE, ['uid'], true);
        }

        if (!$this->db->tableExists(BookRecord::TABLE)) {
            $this->createTable(BookRecord::TABLE, [
                'id' => $this->primaryKey(),
                'readerId' => $this->integer()->notNull(),
                'provider' => $this->string(32)->notNull(),
                'externalId' => $this->string(64)->notNull(),
                'shelf' => $this->string(16)->notNull(),
                'title' => $this->string()->notNull(),
                'subtitle' => $this->string(),
                'authors' => $this->text(),
                'isbn' => $this->string(13),
                'url' => $this->string(2000),
                'coverUrl' => $this->string(2000),
                'coverAssetId' => $this->integer(),
                // The coverUrl that coverAssetId was downloaded from, so a
                // changed cover is fetched again and an unchanged one never is.
                'coverSourceUrl' => $this->string(2000),
                'progress' => $this->tinyInteger()->unsigned(),
                'rating' => $this->decimal(2, 1),
                // Calendar dates, not timestamps: no timezone to get wrong.
                'startedAt' => $this->date(),
                'finishedAt' => $this->date(),
                'addedAt' => $this->date(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'hash' => $this->char(40),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, BookRecord::TABLE, ['readerId', 'provider', 'externalId'], true);
            $this->createIndex(null, BookRecord::TABLE, ['readerId', 'shelf', 'sortOrder'], false);
            $this->addForeignKey(null, BookRecord::TABLE, ['readerId'], ReaderRecord::TABLE, ['id'], 'CASCADE');
            $this->addForeignKey(null, BookRecord::TABLE, ['coverAssetId'], Table::ASSETS, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Remove the readers from project config first, while the tables the
        // handlers write to still exist. Downloaded covers stay in their
        // volume; they are ordinary assets.
        Craft::$app->getProjectConfig()->remove(ReadersService::CONFIG_KEY);

        $this->dropTableIfExists(BookRecord::TABLE);
        $this->dropTableIfExists(ReaderRecord::TABLE);

        return true;
    }
}
