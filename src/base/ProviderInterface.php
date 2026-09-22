<?php

declare(strict_types=1);

namespace viesrood\mybooks\base;

use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Reader;

/**
 * A source of books. Register your own through
 * {@see \viesrood\mybooks\services\ProvidersService::EVENT_REGISTER_PROVIDERS}.
 *
 * Providers are only ever called from the sync (console, queue or a control
 * panel button), never while rendering a page.
 */
interface ProviderInterface
{
    /**
     * Stored in project config and on every book; never change it.
     */
    public static function handle(): string;

    public static function displayName(): string;

    /**
     * False for providers whose books are entered by hand.
     */
    public function supportsSync(): bool;

    /**
     * Whether a reader of this provider needs an account name (a username).
     */
    public function requiresAccount(): bool;

    /**
     * Whether a reader of this provider needs an API token.
     */
    public function requiresToken(): bool;

    /**
     * Fetches the given shelves. Failures are reported per shelf in the
     * result; this method itself should not throw.
     *
     * @param Shelf[] $shelves
     */
    public function fetchShelves(Reader $reader, array $shelves, int $limit): FetchResult;

    /**
     * Checks the reader's settings against the service and returns a short
     * success message, or throws a {@see ProviderException}.
     *
     * @throws ProviderException
     */
    public function testConnection(Reader $reader): string;
}
