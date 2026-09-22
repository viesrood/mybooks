<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\TestCase;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\BookData;

final class BookDataTest extends TestCase
{
    public function testOnlyWebUrlsSurvive(): void
    {
        self::assertSame('https://example.com/a.jpg', BookData::httpUrl(' https://example.com/a.jpg '));
        self::assertSame('https://example.com/a.jpg', BookData::httpUrl('//example.com/a.jpg'));
        self::assertNull(BookData::httpUrl('javascript:alert(1)'));
        self::assertNull(BookData::httpUrl('file:///etc/passwd'));
        self::assertNull(BookData::httpUrl('not a url'));
        self::assertNull(BookData::httpUrl(null));
    }

    public function testIsbnKeepsOnlyValidLengths(): void
    {
        self::assertSame('9780140328721', BookData::isbn('978-0-14-032872-1'));
        self::assertSame('014032872X', BookData::isbn('0-14-032872-x'));
        self::assertNull(BookData::isbn('12345'));
        self::assertNull(BookData::isbn(null));
    }

    public function testDatesFromBothProvidersBecomeCalendarDates(): void
    {
        self::assertSame('2026-03-14', BookData::date('2026/03/14, 09:12:44'));
        self::assertSame('2026-03-04', BookData::date('2026-3-4'));
        self::assertNull(BookData::date('2026/02/30, 10:00:00'));
        self::assertNull(BookData::date('yesterday'));
        self::assertNull(BookData::date(''));
    }

    public function testRatingIsRoundedToHalfStarsAndZeroMeansUnrated(): void
    {
        self::assertSame(4.5, BookData::rating('4.4'));
        self::assertSame(5.0, BookData::rating(7));
        self::assertNull(BookData::rating(0));
        self::assertNull(BookData::rating('n/a'));
    }

    public function testProgressIsClampedToAPercentage(): void
    {
        self::assertSame(25, BookData::progress(24.6));
        self::assertSame(100, BookData::progress(140));
        self::assertSame(0, BookData::progress(-3));
        self::assertNull(BookData::progress(null));
    }

    public function testTextIsTrimmedCollapsedAndEmptyBecomesNull(): void
    {
        self::assertSame('De Aanslag', BookData::text("  De\n\tAanslag "));
        self::assertNull(BookData::text('   '));
        self::assertNull(BookData::text(['array']));
    }

    public function testAuthorsAreUniqueAndClean(): void
    {
        self::assertSame(['Roald Dahl', 'Quentin Blake'], BookData::authors(['Roald Dahl', ' Roald Dahl', '', null, 'Quentin Blake']));
        self::assertSame(['Solo'], BookData::authors('Solo'));
        self::assertSame([], BookData::authors(42));
    }

    public function testHashChangesOnlyWhenStoredValuesChange(): void
    {
        $a = BookData::create('1', Shelf::Reading, ['title' => 'Dune', 'progress' => 10]);
        $b = BookData::create('1', Shelf::Reading, ['title' => ' Dune ', 'progress' => 10.2]);
        $c = BookData::create('1', Shelf::Reading, ['title' => 'Dune', 'progress' => 11]);
        $d = BookData::create('1', Shelf::Read, ['title' => 'Dune', 'progress' => 10]);

        self::assertSame($a->hash(), $b->hash());
        self::assertNotSame($a->hash(), $c->hash());
        self::assertNotSame($a->hash(), $d->hash());
    }
}
