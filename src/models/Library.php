<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use Craft;
use craft\base\Model;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\providers\OpenLibrary;

/**
 * The value of a Books field: someone's books, either picked by hand or
 * synced from a linked Open Library account.
 *
 * {% for book in entry.books.shelf('reading', 4) %}
 *
 * Reading books never contacts Open Library: hand-picked books live in the
 * field value, synced books in the plugin's own table.
 */
class Library extends Model
{
    public const MODE_MANUAL = 'manual';

    public const MODE_OPENLIBRARY = 'openlibrary';

    public string $mode = self::MODE_MANUAL;

    /** Open Library username, kept in both modes so switching loses nothing. */
    public string $account = '';

    /** @var Book[] Hand-picked books, in the order the editor put them. */
    public array $manualBooks = [];

    /** @var Shelf[] Shelves to show when linked. */
    private array $shelves = [Shelf::Want, Shelf::Reading, Shelf::Read];

    /** @var Book[]|null */
    private ?array $books = null;

    public function attributes(): array
    {
        $names = parent::attributes();
        $names[] = 'shelves';

        return $names;
    }

    /**
     * Builds a value from stored JSON, posted form data or anything else;
     * unusable input becomes an empty library, never an error.
     */
    public static function fromFieldData(mixed $data): self
    {
        if (is_string($data) && $data !== '') {
            $data = json_decode($data, true);
        }

        $library = new self();

        if (!is_array($data)) {
            return $library;
        }

        $library->mode = ($data['mode'] ?? null) === self::MODE_OPENLIBRARY ? self::MODE_OPENLIBRARY : self::MODE_MANUAL;
        // Keep what was typed (so a validation error can show it); only a
        // pasted profile URL is reduced to the username.
        $account = is_string($data['account'] ?? null) ? trim($data['account']) : '';
        $normalized = OpenLibrary::normalizeUsername($account);
        $library->account = $normalized !== '' ? $normalized : mb_substr($account, 0, 100);

        if (array_key_exists('shelves', $data)) {
            $library->setShelves($data['shelves']);
        }

        $seen = [];

        foreach (is_array($data['books'] ?? null) ? $data['books'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $book = Book::fromFieldData($item);

            // A duplicated id (a copied row) gets a fresh one.
            if (isset($seen[$book->id])) {
                $book->id = \craft\helpers\StringHelper::UUID();
            }

            $seen[$book->id] = true;
            $library->manualBooks[] = $book;
        }

        return $library;
    }

    /**
     * @return array<string, mixed>
     */
    public function toFieldData(): array
    {
        return [
            'mode' => $this->mode,
            'account' => $this->account !== '' ? $this->account : null,
            'shelves' => $this->getShelfValues(),
            'books' => array_map(static fn(Book $book): array => $book->toFieldData(), $this->manualBooks),
        ];
    }

    public function isLinked(): bool
    {
        return $this->mode === self::MODE_OPENLIBRARY && $this->account !== '';
    }

    public function isEmpty(): bool
    {
        return $this->mode === self::MODE_MANUAL ? $this->manualBooks === [] : $this->account === '';
    }

    /**
     * @return Shelf[]
     */
    public function getShelves(): array
    {
        return $this->shelves;
    }

    public function setShelves(mixed $shelves): void
    {
        $this->shelves = Shelf::listFrom($shelves);
    }

    /**
     * @return string[]
     */
    public function getShelfValues(): array
    {
        return array_map(static fn(Shelf $shelf): string => $shelf->value, $this->shelves);
    }

    /**
     * Every book, in display order.
     *
     * @return Book[]
     */
    public function getBooks(): array
    {
        if ($this->books !== null) {
            return $this->books;
        }

        $plugin = Plugin::getInstance();

        if ($this->isLinked()) {
            $books = $plugin?->getBooks()->getSyncedBooks($this->account) ?? [];
            $wanted = $this->getShelfValues();
            $books = array_values(array_filter(
                $books,
                static fn(Book $book): bool => in_array($book->getShelf()->value, $wanted, true),
            ));
        } elseif ($this->mode === self::MODE_MANUAL) {
            $books = $this->manualBooks;
        } else {
            $books = [];
        }

        $plugin?->getCovers()->eagerLoad($books);

        return $this->books = $books;
    }

    /**
     * Books on one shelf. An unknown shelf gives an empty list, so a typo in
     * a template renders nothing instead of an error.
     *
     * @return Book[]
     */
    public function shelf(mixed $shelf, ?int $limit = null): array
    {
        $shelf = Shelf::tryFromAny($shelf);

        if ($shelf === null) {
            return [];
        }

        $books = array_values(array_filter(
            $this->getBooks(),
            static fn(Book $book): bool => $book->getShelf() === $shelf,
        ));

        return $limit !== null && $limit >= 0 ? array_slice($books, 0, $limit) : $books;
    }

    public function count(mixed $shelf = null): int
    {
        return count($shelf === null ? $this->getBooks() : $this->shelf($shelf));
    }

    public function hasBooks(mixed $shelf = null): bool
    {
        return $this->count($shelf) > 0;
    }

    /**
     * Sync status of the linked account, or null when not linked or never
     * synced.
     */
    public function getAccountStatus(): ?Account
    {
        return $this->isLinked() ? Plugin::getInstance()?->getAccounts()->getAccount($this->account) : null;
    }

    /**
     * Every cover URL of the hand-picked books (for storing them locally).
     *
     * @return string[]
     */
    public function getManualCoverUrls(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(Book $book): ?string => $book->coverUrl,
            $this->manualBooks,
        ))));
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['account'], 'validateAccount', 'skipOnEmpty' => false],
            [['manualBooks'], 'validateBooks', 'skipOnEmpty' => false],
        ];
    }

    public function validateAccount(string $attribute): void
    {
        if ($this->mode !== self::MODE_OPENLIBRARY) {
            return;
        }

        if ($this->account === '') {
            $this->addError($attribute, Craft::t('mybooks', 'Enter an Open Library username.'));
        } elseif (OpenLibrary::normalizeUsername($this->account) === '') {
            $this->addError($attribute, Craft::t('mybooks', 'That is not a valid Open Library username.'));
        }

        if ($this->shelves === []) {
            $this->addError('shelves', Craft::t('mybooks', 'Choose at least one shelf.'));
        }
    }

    public function validateBooks(string $attribute): void
    {
        if ($this->mode !== self::MODE_MANUAL) {
            return;
        }

        foreach ($this->manualBooks as $index => $book) {
            if (!$book->validate()) {
                foreach ($book->getFirstErrors() as $error) {
                    $this->addError($attribute, Craft::t('mybooks', 'Book {number}: {error}', [
                        'number' => $index + 1,
                        'error' => $error,
                    ]));
                }
            }
        }
    }
}
