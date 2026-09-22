<?php

declare(strict_types=1);

namespace viesrood\mybooks\providers;

use Craft;
use viesrood\mybooks\base\FetchResult;
use viesrood\mybooks\base\Provider;
use viesrood\mybooks\models\Reader;

/**
 * Books entered by hand in the control panel. Nothing to sync, nothing that
 * can go down.
 */
class Manual extends Provider
{
    public static function handle(): string
    {
        return 'manual';
    }

    public static function displayName(): string
    {
        return Craft::t('mybooks', 'Entered by hand');
    }

    public function supportsSync(): bool
    {
        return false;
    }

    public function fetchShelves(Reader $reader, array $shelves, int $limit): FetchResult
    {
        return new FetchResult();
    }

    public function testConnection(Reader $reader): string
    {
        return Craft::t('mybooks', 'Books for this reader are entered by hand; there is nothing to connect to.');
    }
}
