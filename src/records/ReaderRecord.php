<?php

declare(strict_types=1);

namespace viesrood\mybooks\records;

use craft\db\ActiveRecord;

/**
 * A reader. Everything except lastSyncedAt and lastError is a mirror of
 * project config (mybooks.readers.<uid>) and is written only by the project
 * config handlers.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $provider
 * @property string|null $account
 * @property string|null $token
 * @property string|null $shelves
 * @property int $sortOrder
 * @property string|null $lastSyncedAt
 * @property string|null $lastError
 * @property string $uid
 */
class ReaderRecord extends ActiveRecord
{
    public const TABLE = '{{%mybooks_readers}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
