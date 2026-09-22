<?php

declare(strict_types=1);

namespace viesrood\mybooks\jobs;

use Craft;
use craft\queue\BaseJob;
use viesrood\mybooks\Plugin;

/**
 * Syncs one account in the background, right after a Books field links to it.
 */
class SyncAccount extends BaseJob
{
    public string $account = '';

    public function execute($queue): void
    {
        $outcome = Plugin::getInstance()?->getSync()->syncAccount($this->account);

        if ($outcome !== null && $outcome->hasErrors()) {
            // Recorded on the account; the job itself succeeds so it is not
            // retried in a tight loop against a service that is down.
            Craft::warning(sprintf('My Books sync of “%s”: %s', $this->account, $outcome->errorSummary()), 'mybooks');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('mybooks', 'Syncing books');
    }
}
