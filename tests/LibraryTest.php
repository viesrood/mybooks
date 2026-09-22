<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\TestCase;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Library;

final class LibraryTest extends TestCase
{
    private const ID = '0b6f9a7e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';

    public function testRoundTripsThroughTheStoredJson(): void
    {
        $stored = [
            'mode' => 'manual',
            'account' => 'jane_doe',
            'shelves' => ['reading'],
            'books' => [[
                'id' => self::ID,
                'shelf' => 'reading',
                'title' => 'Fantastic Mr Fox',
                'authors' => ['Roald Dahl'],
                'workId' => 'OL45804W',
                'url' => 'https://openlibrary.org/works/OL45804W',
                'coverUrl' => 'https://covers.openlibrary.org/b/id/6498519-L.jpg',
                'progress' => 40,
            ]],
        ];

        $library = Library::fromFieldData(json_encode($stored));
        $data = $library->toFieldData();

        self::assertSame('manual', $data['mode']);
        self::assertSame('jane_doe', $data['account'], 'the account survives in manual mode');
        self::assertSame(['reading'], $data['shelves']);
        self::assertSame(self::ID, $data['books'][0]['id']);
        self::assertSame(40, $data['books'][0]['progress']);
        self::assertSame('OL45804W', $data['books'][0]['workId']);
        self::assertEquals($library->toFieldData(), Library::fromFieldData($data)->toFieldData());
    }

    public function testRubbishBecomesAnEmptyLibraryInsteadOfAnError(): void
    {
        foreach ([null, '', 'not json', 42, ['books' => 'nope'], ['mode' => 'hardcover']] as $input) {
            $library = Library::fromFieldData($input);

            self::assertSame(Library::MODE_MANUAL, $library->mode);
            self::assertSame([], $library->manualBooks);
            self::assertTrue($library->isEmpty());
        }
    }

    public function testPostedBooksAreSanitised(): void
    {
        $library = Library::fromFieldData(['books' => [
            [
                'id' => 'not-a-uuid',
                'shelf' => 'nonsense',
                'title' => "  Dune\n",
                'authors' => ['Frank Herbert', '', 'Frank Herbert'],
                'workId' => '<script>',
                'url' => 'javascript:alert(1)',
                'coverUrl' => 'file:///etc/passwd',
                'progress' => 250,
            ],
            ['title' => 'On the read shelf', 'shelf' => 'read', 'progress' => 50],
            'not an array',
        ]]);

        self::assertCount(2, $library->manualBooks);
        [$dune, $read] = $library->manualBooks;

        self::assertMatchesRegularExpression('/^[a-f0-9\-]{36}$/', (string)$dune->id, 'an invalid id is replaced');
        self::assertSame(Shelf::Reading, $dune->getShelf());
        self::assertSame('Dune', $dune->title);
        self::assertSame(['Frank Herbert'], $dune->authors);
        self::assertNull($dune->workId);
        self::assertNull($dune->url);
        self::assertNull($dune->coverUrl);
        self::assertSame(100, $dune->progress);
        self::assertNull($read->progress, 'progress only exists while reading');
    }

    public function testDuplicateIdsGetAFreshOne(): void
    {
        $library = Library::fromFieldData(['books' => [
            ['id' => self::ID, 'title' => 'A'],
            ['id' => self::ID, 'title' => 'B'],
        ]]);

        self::assertNotSame($library->manualBooks[0]->id, $library->manualBooks[1]->id);
    }

    public function testManualShelvesKeepTheEditorsOrder(): void
    {
        $library = Library::fromFieldData(['books' => [
            ['title' => 'Third', 'shelf' => 'reading'],
            ['title' => 'Done', 'shelf' => 'read'],
            ['title' => 'First', 'shelf' => 'reading'],
        ]]);

        $titles = array_map(static fn($book) => $book->title, $library->shelf('currently-reading'));

        self::assertSame(['Third', 'First'], $titles);
        self::assertSame(['Third'], array_map(static fn($book) => $book->title, $library->shelf('reading', 1)));
        self::assertSame([], $library->shelf('typo'));
        self::assertSame(3, $library->count());
    }

    public function testAPastedProfileUrlBecomesTheUsername(): void
    {
        $library = Library::fromFieldData(['mode' => 'openlibrary', 'account' => 'https://openlibrary.org/people/jane_doe']);

        self::assertSame('jane_doe', $library->account);
        self::assertTrue($library->isLinked());
    }

    public function testLinkedModeNeedsAValidUsernameAndAShelf(): void
    {
        $library = Library::fromFieldData(['mode' => 'openlibrary', 'account' => 'jane doe', 'shelves' => []]);

        self::assertFalse($library->validate());
        self::assertArrayHasKey('account', $library->getErrors());
        self::assertArrayHasKey('shelves', $library->getErrors());

        $manual = Library::fromFieldData(['mode' => 'manual', 'account' => 'jane doe']);
        self::assertTrue($manual->validate(), 'an account typed but not used does not block saving');
    }

    public function testEveryHandPickedBookNeedsATitle(): void
    {
        $library = Library::fromFieldData(['books' => [['title' => '   ']]]);

        self::assertFalse($library->validate());
        self::assertStringContainsString('Book 1', implode(' ', $library->getErrors('manualBooks')));
    }
}
