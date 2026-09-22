<?php

declare(strict_types=1);

namespace viesrood\mybooks\base;

use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\BookData;

/**
 * The outcome of fetching one or more shelves.
 *
 * A shelf is either in {@see $books} (fetched, possibly empty) or in
 * {@see $errors} (failed). The sync service only touches shelves that were
 * fetched, so a failure keeps the last good copy of that shelf.
 */
final class FetchResult
{
    /** @var array<string, BookData[]> */
    private array $books = [];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param BookData[] $books
     */
    public function setBooks(Shelf $shelf, array $books): void
    {
        unset($this->errors[$shelf->value]);
        $this->books[$shelf->value] = array_values($books);
    }

    public function setError(Shelf $shelf, string $message): void
    {
        unset($this->books[$shelf->value]);
        $this->errors[$shelf->value] = $message;
    }

    /**
     * @return array<string, BookData[]> Keyed by shelf value.
     */
    public function getBooks(): array
    {
        return $this->books;
    }

    /**
     * @return array<string, string> Keyed by shelf value.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
