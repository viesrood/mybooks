<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

/**
 * What one reader's sync did. Counts only what actually changed.
 */
final class SyncOutcome
{
    public int $added = 0;

    public int $updated = 0;

    public int $removed = 0;

    public int $covers = 0;

    /** @var array<string, string> Failed shelves, keyed by shelf value. */
    public array $errors = [];

    public bool $skipped = false;

    public function changed(): bool
    {
        return $this->added + $this->updated + $this->removed + $this->covers > 0;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function errorSummary(): ?string
    {
        if ($this->errors === []) {
            return null;
        }

        return implode(' ', array_unique(array_values($this->errors)));
    }
}
