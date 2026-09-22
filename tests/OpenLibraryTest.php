<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\providers\OpenLibrary;

final class OpenLibraryTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    public function testMapsEveryUsableEntryAndSkipsTheRest(): void
    {
        $data = self::fixture();
        $books = [];

        foreach ($data['reading_log_entries'] as $entry) {
            $book = OpenLibrary::mapEntry($entry, Shelf::Reading);

            if ($book !== null) {
                $books[] = $book;
            }
        }

        // The fourth entry points at an edition, not a work.
        self::assertCount(3, $books);

        [$fox, $untitled, $aanslag] = $books;

        self::assertSame('OL45804W', $fox->externalId);
        self::assertSame('Fantastic Mr Fox', $fox->title);
        self::assertSame(['Roald Dahl'], $fox->authors);
        self::assertSame('https://covers.openlibrary.org/b/id/6498519-L.jpg', $fox->coverUrl);
        self::assertSame('https://openlibrary.org/works/OL45804W', $fox->url);
        self::assertSame('2026-03-14', $fox->addedAt);

        // Title, cover and authors are all optional in Open Library's model.
        self::assertSame('', $untitled->title);
        self::assertSame([], $untitled->authors);
        self::assertNull($untitled->coverUrl);
        self::assertNull($untitled->addedAt);

        self::assertSame('De Aanslag', $aanslag->title);
        self::assertNull($aanslag->coverUrl, 'cover id 0 is not a cover');
        self::assertNull($aanslag->addedAt, 'an impossible date is dropped');
    }

    public function testFetchesTheRightUrlForEachShelf(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string)json_encode(self::fixture())),
            new Response(200, [], '{"page":1,"numFound":0,"reading_log_entries":[]}'),
        ]);

        $result = $provider->fetchShelves('jane.doe', [Shelf::Reading, Shelf::Want], 50);

        self::assertFalse($result->hasErrors());
        self::assertCount(3, $result->getBooks()['reading']);
        self::assertSame([], $result->getBooks()['want']);

        $urls = array_map(static fn(array $h): string => (string)$h['request']->getUri(), $this->history);
        self::assertSame('https://openlibrary.org/people/jane.doe/books/currently-reading.json?page=1&limit=50', $urls[0]);
        self::assertStringContainsString('/books/want-to-read.json', $urls[1]);
    }

    public function testFollowsPagesUntilTheLimit(): void
    {
        $page = static fn(int $from, int $count): string => (string)json_encode([
            'page' => 1,
            'numFound' => 250,
            'reading_log_entries' => array_map(
                static fn(int $i): array => ['work' => ['key' => '/works/OL' . $i . 'W', 'title' => 'Book ' . $i]],
                range($from, $from + $count - 1),
            ),
        ]);

        $provider = $this->provider([
            new Response(200, [], $page(1, 100)),
            new Response(200, [], $page(101, 100)),
        ]);

        $result = $provider->fetchShelves('jane', [Shelf::Read], 150);

        self::assertCount(150, $result->getBooks()['read']);
        self::assertCount(2, $this->history);
        self::assertStringContainsString('page=2', (string)$this->history[1]['request']->getUri());
    }

    public function testAPrivateLogBecomesAClearErrorForThatShelfOnly(): void
    {
        $provider = $this->provider([
            new Response(403, [], '{"detail":"This reading log is private"}'),
            new Response(200, [], '{"page":1,"numFound":0,"reading_log_entries":[]}'),
        ]);

        $result = $provider->fetchShelves('jane', [Shelf::Reading, Shelf::Read], 50);

        self::assertStringContainsString('private', $result->getErrors()['reading']);
        self::assertArrayNotHasKey('reading', $result->getBooks());
        self::assertSame([], $result->getBooks()['read']);
    }

    public function testUnknownUserAndDownServiceAreReported(): void
    {
        $provider = $this->provider([
            new Response(404, [], '{"detail":"User not found"}'),
            new ConnectException('timeout', new Request('GET', 'https://openlibrary.org')),
        ]);

        $result = $provider->fetchShelves('nobody', [Shelf::Reading, Shelf::Read], 50);

        self::assertStringContainsString('no user called nobody', $result->getErrors()['reading']);
        self::assertStringContainsString('could not be reached', $result->getErrors()['read']);
    }

    public function testAcceptsAPastedProfileUrl(): void
    {
        $provider = $this->provider([new Response(200, [], '{"page":1,"numFound":0,"reading_log_entries":[]}')]);
        $provider->fetchShelves('https://openlibrary.org/people/jane_doe/books/already-read', [Shelf::Reading], 5);

        self::assertStringContainsString('/people/jane_doe/books/', (string)$this->history[0]['request']->getUri());
    }

    public function testAnEmptyUsernameNeverReachesTheNetwork(): void
    {
        $provider = $this->provider([]);
        $result = $provider->fetchShelves('', [Shelf::Reading], 5);

        self::assertArrayHasKey('reading', $result->getErrors());
        self::assertSame([], $this->history);
    }

    public function testSearchByTitleAndByIsbn(): void
    {
        $doc = ['key' => '/works/OL45804W', 'title' => 'Fantastic Mr Fox', 'author_name' => ['Roald Dahl'], 'cover_i' => 6498519];
        $provider = $this->provider([
            new Response(200, [], (string)json_encode(['docs' => [$doc, $doc, ['key' => '/works/OL1W', 'title' => '']]])),
            new Response(200, [], (string)json_encode(['docs' => [$doc]])),
        ]);

        $byTitle = $provider->search('fantastic fox');
        $byIsbn = $provider->search('978-0-14-032872-1');

        self::assertCount(1, $byTitle, 'duplicates and untitled works are dropped');
        self::assertStringContainsString('q=fantastic%20fox', (string)$this->history[0]['request']->getUri());
        self::assertStringContainsString('isbn=9780140328721', (string)$this->history[1]['request']->getUri());
        self::assertStringNotContainsString('q=', (string)$this->history[1]['request']->getUri());
        self::assertSame('9780140328721', $byIsbn[0]->isbn);
    }

    public function testATooShortQueryNeverReachesTheNetwork(): void
    {
        self::assertSame([], $this->provider([])->search(' a '));
        self::assertSame([], $this->history);
    }

    public function testUsernamesAreNormalised(): void
    {
        self::assertSame('jane_doe', OpenLibrary::normalizeUsername(' jane_doe '));
        self::assertSame('jane_doe', OpenLibrary::normalizeUsername('https://openlibrary.org/people/jane_doe/books/want-to-read'));
        self::assertSame('', OpenLibrary::normalizeUsername('jane doe'));
        self::assertSame('', OpenLibrary::normalizeUsername('../../admin'));
        self::assertSame('', OpenLibrary::normalizeUsername(null));
    }

    public function testIsbnSearchDocumentIsMapped(): void
    {
        $book = OpenLibrary::mapSearchDoc([
            'key' => '/works/OL45804W',
            'title' => 'Fantastic Mr Fox',
            'author_name' => ['Roald Dahl', 'Roald Dahl'],
            'cover_i' => 6498519,
        ], '9780140328721');

        self::assertNotNull($book);
        self::assertSame(['Roald Dahl'], $book->authors);
        self::assertSame('9780140328721', $book->isbn);
        self::assertNull(OpenLibrary::mapSearchDoc(null, '9780140328721'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/openlibrary-currently-reading.json'), true);

        return $data;
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function provider(array $responses): OpenLibrary
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new OpenLibrary(new Client(['handler' => $stack]));
    }
}
