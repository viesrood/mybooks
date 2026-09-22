<?php

declare(strict_types=1);

namespace viesrood\mybooks\records;

use craft\db\ActiveRecord;

/**
 * An Open Library account that at least one Books field links to. Rows are
 * created by the sync as it finds accounts in field values, and removed when
 * no field uses the account any more.
 *
 * @property int $id
 * @property string $account
 * @property string|null $lastSyncedAt
 * @property string|null $lastError
 * @property string $uid
 */
class AccountRecord extends ActiveRecord
{
    public const TABLE = '{{%mybooks_accounts}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
