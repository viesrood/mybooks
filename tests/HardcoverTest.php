<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\providers\Hardcover;

final class HardcoverTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    protected function tearDown(): void
    {
        putenv('MYBOOKS_TEST_TOKEN');
        unset($_SERVER['MYBOOKS_TEST_TOKEN'], $_ENV['MYBOOKS_TEST_TOKEN']);
    }

    public function testOneRequestForAllShelves(): void
    {
        $query = Hardcover::buildQuery([Shelf::Reading, Shelf::Read], 20);

        self::assertStringStartsWith('query MyBooks { me {', $query);
        self::assertStringContainsString('reading: user_books(where: {status_id: {_eq: 2}}, order_by: {date_added: desc}, limit: 20)', $query);
        self::assertStringContainsString('read: user_books(where: {status_id: {_eq: 3}}, order_by: {last_read_date: desc_nulls_last}, limit: 20)', $query);
        self::assertStringNotContainsString('want:', $query);
        // Relations deeper than book { ... } would break Hardcover's depth limit.
        self::assertStringNotContainsString('contributions', $query);
        self::assertStringNotContainsString('image {', $query);
    }

    public function testMapsTheResponse(): void
    {
        $me = self::fixture()['data']['me'][0];
        $mapped = Hardcover::mapShelves($me, [Shelf::Reading, Shelf::Read]);

        [$hailMary, $noPages] = $mapped['reading'];

        self::assertSame('428351', $hailMary->externalId);
        self::assertSame(['Andy Weir'], $hailMary->authors, 'narrators are not authors');
        self::assertSame('https://assets.hardcover.app/edition/1/cover.jpg', $hailMary->coverUrl);
        self::assertSame('https://hardcover.app/books/project-hail-mary', $hailMary->url);
        self::assertSame(25, $hailMary->progress);
        self::assertSame('2026-08-02', $hailMary->startedAt);
        self::assertNull($hailMary->finishedAt);

        // JSON columns may arrive as strings; unsafe URLs are dropped.
        self::assertSame(['Jane Doe'], $noPages->authors);
        self::assertNull($noPages->coverUrl);
        self::assertNull($noPages->url);
        self::assertNull($noPages->progress, 'no page count, no percentage');

        self::assertCount(1, $mapped['read'], 'a row without a book is skipped');
        self::assertSame(4.5, $mapped['read'][0]->rating);
        self::assertSame('2025-02-10', $mapped['read'][0]->finishedAt);
        self::assertNull($mapped['read'][0]->progress);
    }

    public function testSendsTheTokenFromTheEnvironment(): void
    {
        $this->setToken('Bearer abc123');
        $provider = $this->provider([new Response(200, [], (string)json_encode(self::fixture()))]);

        $result = $provider->fetchShelves($this->reader(), [Shelf::Reading, Shelf::Read], 20);

        self::assertFalse($result->hasErrors());
        self::assertCount(1, $this->history);
        self::assertSame('Bearer abc123', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testAnExpiredTokenFailsEveryShelfWithAClearMessage(): void
    {
        $this->setToken('abc');
        $provider = $this->provider([new Response(401, [], '{"error":"invalid_token"}')]);

        $result = $provider->fetchShelves($this->reader(), [Shelf::Reading, Shelf::Read], 20);

        self::assertStringContainsString('did not accept the token', $result->getErrors()['reading']);
        self::assertArrayHasKey('read', $result->getErrors());
        self::assertSame([], $result->getBooks());
    }

    public function testGraphqlErrorsAreReported(): void
    {
        $this->setToken('abc');
        $provider = $this->provider([new Response(200, [], '{"errors":[{"message":"field \"x\" not found"}]}')]);

        $result = $provider->fetchShelves($this->reader(), [Shelf::Reading], 20);

        self::assertStringContainsString('field "x" not found', $result->getErrors()['reading']);
    }

    public function testAMissingTokenNeverReachesTheNetwork(): void
    {
        $provider = $this->provider([]);
        $result = $provider->fetchShelves($this->reader(), [Shelf::Reading], 20);

        self::assertStringContainsString('token', $result->getErrors()['reading']);
        self::assertSame([], $this->history);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string)file_get_contents(__DIR__ . '/fixtures/hardcover-me.json'), true);

        return $data;
    }

    private function setToken(string $token): void
    {
        putenv('MYBOOKS_TEST_TOKEN=' . $token);
        $_SERVER['MYBOOKS_TEST_TOKEN'] = $token;
    }

    private function reader(): Reader
    {
        return new Reader(['name' => 'Jane', 'handle' => 'jane', 'provider' => 'hardcover', 'token' => '$MYBOOKS_TEST_TOKEN']);
    }

    /**
     * @param array<int, Response> $responses
     */
    private function provider(array $responses): Hardcover
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Hardcover(new Client(['handler' => $stack]));
    }
}
