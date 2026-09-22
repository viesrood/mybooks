<?php

declare(strict_types=1);

namespace viesrood\mybooks\records;

use craft\db\ActiveRecord;

/**
 * The local copy of one remote cover URL, shared by every book that uses it.
 *
 * status "stored" has an asset; "rejected" means the URL will never give a
 * usable cover (a placeholder, not an image, a 404) and is not fetched again.
 *
 * @property int $id
 * @property string $urlHash
 * @property string $url
 * @property int|null $assetId
 * @property string $status
 * @property string $uid
 */
class CoverRecord extends ActiveRecord
{
    public const TABLE = '{{%mybooks_covers}}';

    public const STATUS_STORED = 'stored';

    public const STATUS_REJECTED = 'rejected';

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public static function hash(string $url): string
    {
        return sha1($url);
    }
}
