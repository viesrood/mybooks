<?php

declare(strict_types=1);

namespace viesrood\mybooks\events;

use viesrood\mybooks\models\Reader;
use yii\base\Event;

/**
 * Fired after a reader has been synced.
 *
 * Use it to clear a static page cache, but only when $changed is true: a
 * quiet sync (nothing added, changed or removed) should cost nothing.
 */
class SyncEvent extends Event
{
    public ?Reader $reader = null;

    /** Whether any book or cover was added, changed or removed. */
    public bool $changed = false;

    /** @var array<string, string> Failed shelves, keyed by shelf value. */
    public array $errors = [];
}
