<?php

declare(strict_types=1);

namespace viesrood\mybooks\jobs;

use Craft;
use craft\queue\BaseJob;
use viesrood\mybooks\Plugin;

/**
 * Syncs one reader in the background (after saving a reader, or from the
 * "Sync now" button).
 */
class SyncReader extends BaseJob
{
    public int $readerId = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $reader = $plugin?->getReaders()->getReaderById($this->readerId);

        if ($plugin === null || $reader === null) {
            return;
        }

        $outcome = $plugin->getSync()->syncReader($reader);

        if ($outcome->hasErrors()) {
            // Recorded on the reader; the job itself succeeds so it is not
            // retried in a tight loop against a service that is down.
            Craft::warning(sprintf('My Books sync of “%s”: %s', $reader->handle, $outcome->errorSummary()), 'mybooks');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('mybooks', 'Syncing books');
    }
}
