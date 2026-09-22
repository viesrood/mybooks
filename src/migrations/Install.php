<?php

declare(strict_types=1);

namespace viesrood\mybooks\migrations;

use craft\db\Migration;
use craft\db\Table;
use viesrood\mybooks\records\AccountRecord;
use viesrood\mybooks\records\BookRecord;
use viesrood\mybooks\records\CoverRecord;

/**
 * Creates the sync cache (accounts and their books) and the cover cache.
 * Hand-picked books need no table: they live in the field values.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(AccountRecord::TABLE)) {
            $this->createTable(AccountRecord::TABLE, [
                'id' => $this->primaryKey(),
                'account' => $this->string(100)->notNull(),
                'lastSyncedAt' => $this->dateTime(),
                'lastError' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, AccountRecord::TABLE, ['account'], true);
        }

        if (!$this->db->tableExists(BookRecord::TABLE)) {
            $this->createTable(BookRecord::TABLE, [
                'id' => $this->primaryKey(),
                'accountId' => $this->integer()->notNull(),
                'externalId' => $this->string(64)->notNull(),
                'shelf' => $this->string(16)->notNull(),
                'title' => $this->string()->notNull(),
                'subtitle' => $this->string(),
                'authors' => $this->text(),
                'isbn' => $this->string(13),
                'url' => $this->string(2000),
                'coverUrl' => $this->string(2000),
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

            $this->createIndex(null, BookRecord::TABLE, ['accountId', 'externalId'], true);
            $this->createIndex(null, BookRecord::TABLE, ['accountId', 'shelf', 'sortOrder'], false);
            $this->addForeignKey(null, BookRecord::TABLE, ['accountId'], AccountRecord::TABLE, ['id'], 'CASCADE');
        }

        if (!$this->db->tableExists(CoverRecord::TABLE)) {
            $this->createTable(CoverRecord::TABLE, [
                'id' => $this->primaryKey(),
                'urlHash' => $this->char(40)->notNull(),
                'url' => $this->string(2000)->notNull(),
                'assetId' => $this->integer(),
                'status' => $this->string(16)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, CoverRecord::TABLE, ['urlHash'], true);
            $this->addForeignKey(null, CoverRecord::TABLE, ['assetId'], Table::ASSETS, ['id'], 'SET NULL');
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Downloaded covers stay in their volume; they are ordinary assets.
        $this->dropTableIfExists(CoverRecord::TABLE);
        $this->dropTableIfExists(BookRecord::TABLE);
        $this->dropTableIfExists(AccountRecord::TABLE);

        return true;
    }
}
