<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use viesrood\mybooks\services\CoversService;

/**
 * Cover URLs come from Open Library and from the field form, so the server
 * must never be talked into fetching something internal.
 */
final class CoversServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/cover.jpg'],
            'private range' => ['https://10.0.0.5/cover.jpg'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
            'ipv6 loopback' => ['http://[::1]/cover.jpg'],
            'not http' => ['ftp://example.com/cover.jpg'],
            'no host' => ['https:///cover.jpg'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesInternalAndNonWebUrls(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        CoversService::assertPublicUrl($url);
    }

    public function testAcceptsAPublicAddress(): void
    {
        CoversService::assertPublicUrl('https://1.1.1.1/cover.jpg');
        $this->addToAssertionCount(1);
    }
}
