<?php

declare(strict_types=1);

namespace viesrood\mybooks\providers;

use Craft;
use viesrood\mybooks\base\FetchResult;
use viesrood\mybooks\base\Provider;
use viesrood\mybooks\base\ProviderException;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\models\Reader;

/**
 * Hardcover's GraphQL API, authenticated with the reader's own token.
 *
 * All requested shelves come back in a single request (one top-level `me`
 * query with an alias per shelf), which counts as one call against
 * Hardcover's rate limit. The query stays within a nesting depth of three by
 * reading the cached_image and cached_contributors JSON columns instead of
 * the image and contributions relations.
 *
 * Hardcover's terms: a public website may only show user data on behalf of
 * the user who owns it, which is exactly what a reader with their own token
 * is. Covers are uploaded by Hardcover users, so keep a takedown policy.
 *
 * @see https://docs.hardcover.app/api/getting-started/
 */
class Hardcover extends Provider
{
    public const ENDPOINT = 'https://api.hardcover.app/v1/graphql';

    public const WEB_URL = 'https://hardcover.app';

    public static function handle(): string
    {
        return 'hardcover';
    }

    public static function displayName(): string
    {
        return 'Hardcover';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public static function statusId(Shelf $shelf): int
    {
        return match ($shelf) {
            Shelf::Want => 1,
            Shelf::Reading => 2,
            Shelf::Read => 3,
        };
    }

    public function testConnection(Reader $reader): string
    {
        $user = $this->me($this->query('query MyBooksTest { me { username } }', $reader), $reader);

        return Craft::t('mybooks', 'Connected to Hardcover as {account}.', [
            'account' => is_string($user['username'] ?? null) ? $user['username'] : '?',
        ]);
    }

    public function fetchShelves(Reader $reader, array $shelves, int $limit): FetchResult
    {
        $result = new FetchResult();

        if ($shelves === []) {
            return $result;
        }

        try {
            $user = $this->me($this->query(self::buildQuery($shelves, $limit), $reader), $reader);
        } catch (ProviderException $e) {
            // One request for every shelf, so one failure fails them all.
            foreach ($shelves as $shelf) {
                $result->setError($shelf, $e->getMessage());
            }

            return $result;
        }

        foreach (self::mapShelves($user, $shelves) as $shelf => $books) {
            $result->setBooks(Shelf::from($shelf), $books);
        }

        return $result;
    }

    /**
     * @param Shelf[] $shelves
     */
    public static function buildQuery(array $shelves, int $limit): string
    {
        $limit = max(1, $limit);
        $parts = [];

        foreach ($shelves as $shelf) {
            // "Read" is sorted by when it was finished; the other shelves by
            // when the book was added.
            $orderBy = $shelf === Shelf::Read
                ? '{last_read_date: desc_nulls_last}'
                : '{date_added: desc}';

            $parts[] = sprintf(
                '%s: user_books(where: {status_id: {_eq: %d}}, order_by: %s, limit: %d) { %s }',
                $shelf->value,
                self::statusId($shelf),
                $orderBy,
                $limit,
                'id rating date_added first_started_reading_date last_read_date'
                . ' book { id title subtitle slug pages cached_image cached_contributors }'
                . ' user_book_reads(order_by: {id: desc}, limit: 1) { progress_pages }',
            );
        }

        return 'query MyBooks { me { ' . implode(' ', $parts) . ' } }';
    }

    /**
     * @param array<mixed> $user The `me` object.
     * @param Shelf[] $shelves
     * @return array<string, BookData[]>
     */
    public static function mapShelves(array $user, array $shelves): array
    {
        $mapped = [];

        foreach ($shelves as $shelf) {
            $rows = $user[$shelf->value] ?? [];
            $books = [];

            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && ($book = self::mapUserBook($row, $shelf)) !== null) {
                    $books[$book->externalId] = $book;
                }
            }

            $mapped[$shelf->value] = array_values($books);
        }

        return $mapped;
    }

    /**
     * @param array<mixed> $row One user_books row.
     */
    public static function mapUserBook(array $row, Shelf $shelf): ?BookData
    {
        $book = $row['book'] ?? null;

        if (!is_array($book) || !is_numeric($book['id'] ?? null)) {
            return null;
        }

        $slug = is_string($book['slug'] ?? null) && $book['slug'] !== '' ? $book['slug'] : null;

        return BookData::create((string)(int)$book['id'], $shelf, [
            'title' => $book['title'] ?? null,
            'subtitle' => $book['subtitle'] ?? null,
            'authors' => self::authors($book['cached_contributors'] ?? null),
            'url' => $slug !== null ? self::WEB_URL . '/books/' . rawurlencode($slug) : null,
            'coverUrl' => self::coverUrl($book['cached_image'] ?? null),
            'progress' => $shelf === Shelf::Reading ? self::progress($row, $book) : null,
            'rating' => $row['rating'] ?? null,
            'addedAt' => $row['date_added'] ?? null,
            'startedAt' => $row['first_started_reading_date'] ?? null,
            'finishedAt' => $shelf === Shelf::Read ? ($row['last_read_date'] ?? null) : null,
        ]);
    }

    /**
     * cached_contributors is a JSON list of {author: {name}, contribution}.
     * Only authors count: a null or "Author" contribution. Translators,
     * narrators and illustrators are left out.
     *
     * @return string[]
     */
    public static function authors(mixed $contributors): array
    {
        if (is_string($contributors)) {
            $contributors = json_decode($contributors, true);
        }

        if (!is_array($contributors)) {
            return [];
        }

        $names = [];

        foreach ($contributors as $contributor) {
            if (!is_array($contributor)) {
                continue;
            }

            $role = $contributor['contribution'] ?? null;

            if ($role !== null && $role !== '' && strcasecmp((string)$role, 'Author') !== 0) {
                continue;
            }

            $name = $contributor['author']['name'] ?? $contributor['name'] ?? null;

            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return BookData::authors($names);
    }

    public static function coverUrl(mixed $image): ?string
    {
        if (is_string($image)) {
            $image = json_decode($image, true);
        }

        return is_array($image) ? BookData::httpUrl($image['url'] ?? null) : null;
    }

    /**
     * Hardcover stores progress in pages; turned into a percentage using the
     * book's page count.
     *
     * @param array<mixed> $row
     * @param array<mixed> $book
     */
    private static function progress(array $row, array $book): ?int
    {
        $reads = $row['user_book_reads'] ?? [];
        $pages = $book['pages'] ?? null;

        if (!is_array($reads) || !is_array($reads[0] ?? null) || !is_numeric($pages) || (int)$pages <= 0) {
            return null;
        }

        $done = $reads[0]['progress_pages'] ?? null;

        return is_numeric($done) ? BookData::progress((float)$done / (int)$pages * 100) : null;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     * @throws ProviderException
     */
    private function me(array $data, Reader $reader): array
    {
        if (isset($data['errors']) && is_array($data['errors'])) {
            $first = $data['errors'][0] ?? null;
            $message = is_array($first) && is_string($first['message'] ?? null) ? $first['message'] : 'unknown error';

            throw new ProviderException(Craft::t('mybooks', 'Hardcover rejected the request: {message}', [
                'message' => mb_substr($message, 0, 200),
            ]));
        }

        // `me` is a list with one user in Hardcover's schema.
        $me = $data['data']['me'] ?? null;

        if (is_array($me) && array_is_list($me)) {
            $me = $me[0] ?? null;
        }

        if (!is_array($me)) {
            throw new ProviderException(Craft::t('mybooks', 'Hardcover did not recognise the token of {reader}.', [
                'reader' => $reader->name,
            ]));
        }

        return $me;
    }

    /**
     * @return array<mixed>
     * @throws ProviderException
     */
    private function query(string $query, Reader $reader): array
    {
        return $this->requestJson('POST', self::ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token($reader),
                'Content-Type' => 'application/json',
            ],
            'json' => ['query' => $query],
        ], [
            401 => Craft::t('mybooks', 'Hardcover did not accept the token. It may have expired; create a new one under Account → Hardcover API.'),
            403 => Craft::t('mybooks', 'The Hardcover token is missing a permission. Give it read access to your library.'),
            429 => Craft::t('mybooks', 'Hardcover’s rate limit was reached. The next sync will try again.'),
        ]);
    }

    /**
     * @throws ProviderException
     */
    private function token(Reader $reader): string
    {
        $token = $reader->getParsedToken();

        // Tokens are shown as "Bearer eyJ..." on Hardcover's settings page;
        // accept either form.
        $token = trim((string)preg_replace('/^Bearer\s+/i', '', trim($token)));

        if ($token === '') {
            throw new ProviderException(Craft::t('mybooks', 'The Hardcover token of {reader} is empty. Check the environment variable.', [
                'reader' => $reader->name,
            ]));
        }

        return $token;
    }
}
