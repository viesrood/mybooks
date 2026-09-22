<?php

declare(strict_types=1);

namespace viesrood\mybooks\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $readerId
 * @property string $provider
 * @property string $externalId
 * @property string $shelf
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $authors
 * @property string|null $isbn
 * @property string|null $url
 * @property string|null $coverUrl
 * @property int|null $coverAssetId
 * @property string|null $coverSourceUrl
 * @property int|null $progress
 * @property float|null $rating
 * @property string|null $startedAt
 * @property string|null $finishedAt
 * @property string|null $addedAt
 * @property int $sortOrder
 * @property string|null $hash
 * @property string $uid
 */
class BookRecord extends ActiveRecord
{
    public const TABLE = '{{%mybooks_books}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
