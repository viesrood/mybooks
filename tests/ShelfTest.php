<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\TestCase;
use viesrood\mybooks\enums\Shelf;

final class ShelfTest extends TestCase
{
    public function testAcceptsTheNamesOtherServicesUse(): void
    {
        self::assertSame(Shelf::Reading, Shelf::tryFromAny('currently-reading'));
        self::assertSame(Shelf::Reading, Shelf::tryFromAny(' Reading '));
        self::assertSame(Shelf::Want, Shelf::tryFromAny('want-to-read'));
        self::assertSame(Shelf::Read, Shelf::tryFromAny('already-read'));
        self::assertSame(Shelf::Read, Shelf::tryFromAny(Shelf::Read));
    }

    public function testUnknownValuesGiveNullInsteadOfAnError(): void
    {
        // A typo in a template must render nothing, not a 500.
        self::assertNull(Shelf::tryFromAny('readng'));
        self::assertNull(Shelf::tryFromAny(null));
        self::assertNull(Shelf::tryFromAny(2));
    }

    public function testListFromDeduplicatesAndUsesCanonicalOrder(): void
    {
        self::assertSame([Shelf::Want, Shelf::Read], Shelf::listFrom(['read', 'want-to-read', 'read', 'nonsense']));
        self::assertSame([Shelf::Reading, Shelf::Read], Shelf::listFrom('read,reading'));
        self::assertSame([], Shelf::listFrom(''));
        self::assertSame([], Shelf::listFrom(null));
    }
}
