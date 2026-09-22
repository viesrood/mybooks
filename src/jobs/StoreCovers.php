<?php

declare(strict_types=1);

namespace viesrood\mybooks\jobs;

use Craft;
use craft\queue\BaseJob;
use viesrood\mybooks\Plugin;

/**
 * Stores the covers of hand-picked books locally, after the element is saved.
 */
class StoreCovers extends BaseJob
{
    /** @var array<string, string|null> Cover URL => book title */
    public array $covers = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if ($plugin !== null) {
            $plugin->getCovers()->store($this->covers, max(1, count($this->covers)));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('mybooks', 'Storing book covers');
    }
}
