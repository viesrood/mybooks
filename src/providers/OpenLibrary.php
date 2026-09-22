<?php

declare(strict_types=1);

namespace viesrood\mybooks\providers;

use Craft;
use viesrood\mybooks\base\Provider;
use viesrood\mybooks\base\ProviderException;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\BookData;

/**
 * Open Library's public reading log.
 *
 * No key needed, but the reader has to make their reading log public
 * (openlibrary.org → Settings → Privacy). A private log answers 403.
 *
 * @see https://github.com/internetarchive/openlibrary/blob/master/openlibrary/fastapi/public_my_books.py
 */
class OpenLibrary extends Provider
{
    public const BASE_URL = 'https://openlibrary.org';

    /** Open Library's own maximum page size. */
    private const PAGE_SIZE = 100;

    public static function displayName(): string
    {
        return 'Open Library';
    }

    public static function shelfKey(Shelf $shelf): string
    {
        return match ($shelf) {
            Shelf::Want => 'want-to-read',
            Shelf::Reading => 'currently-reading',
            Shelf::Read => 'already-read',
        };
    }

    public function testConnection(string $account): string
    {
        $data = $this->requestPage($account, Shelf::Reading, 1, 1);

        return Craft::t('mybooks', 'Connected to the public reading log of {account}.', [
            'account' => self::normalizeUsername($account),
        ]) . ' ' . Craft::t('mybooks', '{count, plural, =0{No books} =1{One book} other{# books}} on “{shelf}”.', [
            'count' => (int)($data['numFound'] ?? 0),
            'shelf' => Shelf::Reading->label(),
        ]);
    }

    protected function fetchShelf(string $account, Shelf $shelf, int $limit): array
    {
        $books = [];
        $page = 1;

        while (count($books) < $limit) {
            $data = $this->requestPage($account, $shelf, $page, min(self::PAGE_SIZE, $limit));
            $entries = $data['reading_log_entries'] ?? null;

            if (!is_array($entries) || $entries === []) {
                break;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && ($book = self::mapEntry($entry, $shelf)) !== null) {
                    $books[$book->externalId] = $book;
                }
            }

            $numFound = (int)($data['numFound'] ?? 0);

            if (count($entries) < min(self::PAGE_SIZE, $limit) || $page * min(self::PAGE_SIZE, $limit) >= $numFound) {
                break;
            }

            $page++;
        }

        return array_slice(array_values($books), 0, $limit);
    }

    /**
     * Maps one reading-log entry. Every field except the work key is optional
     * in Open Library's model (title, cover and authors can all be missing),
     * so nothing here assumes they exist.
     *
     * @param array<mixed> $entry
     */
    public static function mapEntry(array $entry, Shelf $shelf): ?BookData
    {
        $work = $entry['work'] ?? null;

        if (!is_array($work) || !is_string($work['key'] ?? null)) {
            return null;
        }

        // "/works/OL45804W" → "OL45804W"
        $workId = basename($work['key']);

        if (preg_match('/^OL\d+W$/', $workId) !== 1) {
            return null;
        }

        $coverId = $work['cover_id'] ?? null;

        return BookData::create($workId, $shelf, [
            'title' => $work['title'] ?? null,
            'authors' => $work['author_names'] ?? [],
            'url' => self::BASE_URL . '/works/' . $workId,
            'coverUrl' => is_int($coverId) && $coverId > 0 ? self::coverUrl($coverId) : null,
            'addedAt' => $entry['logged_date'] ?? null,
        ]);
    }

    /**
     * Searches Open Library by title, author or ISBN, for the Books field.
     * An ISBN (10 or 13 digits, dashes allowed) searches on ISBN only.
     *
     * @return BookData[]
     * @throws ProviderException
     */
    public function search(string $query, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $isbn = BookData::isbn($query);
        $params = $isbn !== null ? ['isbn' => $isbn] : ['q' => mb_substr($query, 0, 200)];

        $data = $this->requestJson('GET', self::BASE_URL . '/search.json', [
            'query' => $params + [
                'fields' => 'key,title,subtitle,author_name,cover_i',
                'limit' => max(1, min(20, $limit)),
            ],
        ]);

        $books = [];

        foreach (is_array($data['docs'] ?? null) ? $data['docs'] : [] as $doc) {
            $book = self::mapSearchDoc($doc, $isbn);

            if ($book !== null && $book->title !== '') {
                $books[$book->externalId] = $book;
            }
        }

        return array_values($books);
    }

    /**
     * @param mixed $doc One document from search.json.
     */
    public static function mapSearchDoc(mixed $doc, ?string $isbn = null): ?BookData
    {
        if (!is_array($doc) || !is_string($doc['key'] ?? null)) {
            return null;
        }

        $workId = basename($doc['key']);

        if (preg_match('/^OL\d+W$/', $workId) !== 1) {
            return null;
        }

        $coverId = $doc['cover_i'] ?? null;

        return BookData::create($workId, Shelf::Reading, [
            'title' => $doc['title'] ?? null,
            'subtitle' => $doc['subtitle'] ?? null,
            'authors' => $doc['author_name'] ?? [],
            'isbn' => $isbn,
            'url' => self::BASE_URL . '/works/' . $workId,
            'coverUrl' => is_int($coverId) && $coverId > 0 ? self::coverUrl($coverId) : null,
        ]);
    }

    public static function coverUrl(int $coverId): string
    {
        // Cover-ID lookups are not rate limited (ISBN lookups are).
        return 'https://covers.openlibrary.org/b/id/' . $coverId . '-L.jpg';
    }

    /**
     * @return array<mixed>
     * @throws ProviderException
     */
    private function requestPage(string $account, Shelf $shelf, int $page, int $limit): array
    {
        $username = self::normalizeUsername($account);

        if ($username === '') {
            throw new ProviderException(Craft::t('mybooks', 'Enter an Open Library username.'));
        }

        return $this->requestJson('GET', sprintf(
            '%s/people/%s/books/%s.json',
            self::BASE_URL,
            rawurlencode($username),
            self::shelfKey($shelf),
        ), [
            'query' => ['page' => $page, 'limit' => $limit],
        ], [
            403 => Craft::t('mybooks', 'The reading log of {account} is private. Make it public in the Open Library privacy settings.', ['account' => $username]),
            404 => Craft::t('mybooks', 'Open Library has no user called {account}.', ['account' => $username]),
        ]);
    }

    /**
     * A bare username from whatever was typed or pasted: the username itself,
     * or a profile URL such as https://openlibrary.org/people/jane/books.
     * Returns '' when nothing usable is left.
     */
    public static function normalizeUsername(?string $value): string
    {
        $username = trim((string)$value);

        if (preg_match('#openlibrary\.org/people/([^/?\#]+)#', $username, $m) === 1) {
            $username = rawurldecode($m[1]);
        }

        return preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $username) === 1 ? $username : '';
    }
}
