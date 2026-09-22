<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

use Craft;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Book;
use viesrood\mybooks\records\AccountRecord;
use viesrood\mybooks\records\BookRecord;
use yii\base\Component;

/**
 * Reads synced books from the local table. Loaded once per account per
 * request; never any HTTP.
 */
class BooksService extends Component
{
    /** @var array<string, Book[]> keyed by lower-case account */
    private array $byAccount = [];

    /**
     * @return Book[] Grouped by shelf (want, reading, read), then in the
     *                order Open Library returned them.
     */
    public function getSyncedBooks(string $account): array
    {
        $key = strtolower($account);

        if (isset($this->byAccount[$key])) {
            return $this->byAccount[$key];
        }

        if (!Craft::$app->getDb()->tableExists(BookRecord::TABLE)) {
            return $this->byAccount[$key] = [];
        }

        /** @var BookRecord[] $records */
        $records = BookRecord::find()
            ->alias('b')
            ->innerJoin(['a' => AccountRecord::TABLE], '[[a.id]] = [[b.accountId]]')
            ->where(['a.account' => $account])
            ->orderBy(['b.sortOrder' => SORT_ASC, 'b.id' => SORT_ASC])
            ->all();

        $books = array_map(static fn(BookRecord $record): Book => Book::fromRecord($record), $records);
        $order = array_flip(array_map(static fn(Shelf $shelf): string => $shelf->value, Shelf::cases()));

        // usort is stable, so the sort order within a shelf survives.
        usort($books, static fn(Book $a, Book $b): int => $order[$a->getShelf()->value] <=> $order[$b->getShelf()->value]);

        return $this->byAccount[$key] = $books;
    }

    public function forget(?string $account = null): void
    {
        if ($account === null) {
            $this->byAccount = [];
        } else {
            unset($this->byAccount[strtolower($account)]);
        }
    }
}
