<?php

declare(strict_types=1);

namespace viesrood\mybooks\events;

use yii\base\Event;

/**
 * Fired after a linked Open Library account has been synced.
 *
 * Use it to clear a static page cache for $elements, but only when $changed
 * is true: a quiet sync (nothing added, changed or removed) should cost
 * nothing.
 */
class SyncEvent extends Event
{
    public string $account = '';

    /** Whether any book or cover was added, changed or removed. */
    public bool $changed = false;

    /** @var array<string, string> Failed shelves, keyed by shelf value. */
    public array $errors = [];

    /**
     * @var array<int, array{id: int, siteId: int, uri: string|null, title: string, account: string}>
     * The elements whose Books field links to this account.
     */
    public array $elements = [];
}
