<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use viesrood\mybooks\records\AccountRecord;

/**
 * The sync status of one linked Open Library account.
 */
class Account extends Model
{
    public ?int $id = null;

    public string $account = '';

    public ?DateTime $lastSyncedAt = null;

    public ?string $lastError = null;

    public static function fromRecord(AccountRecord $record): self
    {
        // Stored in UTC; toDateTime() reads it as such.
        $synced = $record->lastSyncedAt !== null ? DateTimeHelper::toDateTime($record->lastSyncedAt) : false;

        return new self([
            'id' => (int)$record->id,
            'account' => $record->account,
            'lastSyncedAt' => $synced instanceof DateTime ? $synced : null,
            'lastError' => $record->lastError,
        ]);
    }
}
