<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\BookRecord;

/**
 * One book, hand-picked in a Books field or synced from a linked account.
 * Both kinds look the same to a template:
 *
 * book.title, book.authorsString, book.cover (Asset|null), book.coverSrc,
 * book.progress, book.rating, book.startedDate, book.finishedDate, book.url
 */
class Book extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_OPENLIBRARY = 'openlibrary';

    /** A UUID for hand-picked books, the row id for synced ones. */
    public ?string $id = null;

    public string $source = self::SOURCE_MANUAL;

    /** Open Library work id ("OL45804W"), if known. */
    public ?string $workId = null;

    public string $title = '';

    public ?string $subtitle = null;

    /** @var string[] */
    public array $authors = [];

    public ?string $isbn = null;

    public ?string $url = null;

    public ?string $coverUrl = null;

    public ?int $progress = null;

    public ?float $rating = null;

    /** Y-m-d */
    public ?string $startedAt = null;

    /** Y-m-d */
    public ?string $finishedAt = null;

    /** Y-m-d */
    public ?string $addedAt = null;

    private Shelf $shelf = Shelf::Reading;

    private Asset|false|null $cover = null;

    public function __toString(): string
    {
        return $this->title;
    }

    public function attributes(): array
    {
        $names = parent::attributes();
        $names[] = 'shelf';

        return $names;
    }

    public function getShelf(): Shelf
    {
        return $this->shelf;
    }

    public function setShelf(mixed $shelf): void
    {
        $this->shelf = Shelf::tryFromAny($shelf) ?? Shelf::Reading;
    }

    public function getShelfLabel(): string
    {
        return $this->shelf->label();
    }

    public function getAuthorsString(string $glue = ', ', ?string $lastGlue = null): string
    {
        $authors = $this->authors;

        if ($lastGlue === null || count($authors) < 2) {
            return implode($glue, $authors);
        }

        $last = array_pop($authors);

        return implode($glue, $authors) . $lastGlue . $last;
    }

    /**
     * The local copy of the cover, once the plugin has stored it. Use this
     * with image transforms or ImgixKit; it never touches Open Library.
     */
    public function getCover(): ?Asset
    {
        if ($this->cover === null) {
            $this->cover = ($this->coverUrl !== null
                ? Plugin::getInstance()?->getCovers()->assetFor($this->coverUrl)
                : null) ?? false;
        }

        return $this->cover ?: null;
    }

    /**
     * Used by eager loading, so a whole shelf costs one query.
     */
    public function setCover(?Asset $asset): void
    {
        $this->cover = $asset ?? false;
    }

    /**
     * A URL for an <img>, or null.
     *
     * With a cover volume configured this is only ever the local copy: a
     * cover that has not been stored yet gives null (show a placeholder)
     * rather than a link to Open Library, so no visitor's browser contacts a
     * third party. Without a cover volume the remote cover is returned.
     *
     * @param mixed $transform Passed on to Asset::getUrl().
     */
    public function getCoverSrc(mixed $transform = null): ?string
    {
        $asset = $this->getCover();

        if ($asset !== null) {
            return $asset->getUrl($transform);
        }

        $covers = Plugin::getInstance()?->getCovers();

        return $covers !== null && $covers->isEnabled() ? null : $this->coverUrl;
    }

    public function getStartedDate(): ?DateTime
    {
        return self::toDate($this->startedAt);
    }

    public function getFinishedDate(): ?DateTime
    {
        return self::toDate($this->finishedAt);
    }

    public function getAddedDate(): ?DateTime
    {
        return self::toDate($this->addedAt);
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * A hand-picked book from the stored field JSON (or from the form).
     * Everything is sanitised again, because field values can be posted by
     * anyone who can edit the element.
     *
     * @param array<mixed> $data
     */
    public static function fromFieldData(array $data): self
    {
        $book = new self();
        $id = is_string($data['id'] ?? null) && preg_match('/^[a-f0-9\-]{36}$/', $data['id']) === 1
            ? $data['id']
            : StringHelper::UUID();
        $workId = is_string($data['workId'] ?? null) && preg_match('/^OL\d+W$/', $data['workId']) === 1
            ? $data['workId']
            : null;

        $book->id = $id;
        $book->source = self::SOURCE_MANUAL;
        $book->workId = $workId;
        $book->title = BookData::text($data['title'] ?? null) ?? '';
        $book->subtitle = BookData::text($data['subtitle'] ?? null);
        $book->authors = BookData::authors($data['authors'] ?? []);
        $book->isbn = BookData::isbn($data['isbn'] ?? null);
        $book->url = BookData::httpUrl($data['url'] ?? null);
        $book->coverUrl = BookData::httpUrl($data['coverUrl'] ?? null);
        $book->setShelf($data['shelf'] ?? null);
        $book->progress = $book->getShelf() === Shelf::Reading ? BookData::progress($data['progress'] ?? null) : null;
        $book->rating = BookData::rating($data['rating'] ?? null);
        $book->startedAt = BookData::date($data['startedAt'] ?? null);
        $book->finishedAt = BookData::date($data['finishedAt'] ?? null);

        return $book;
    }

    /**
     * What a hand-picked book stores in the field value.
     *
     * @return array<string, mixed>
     */
    public function toFieldData(): array
    {
        return [
            'id' => $this->id,
            'shelf' => $this->shelf->value,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'authors' => $this->authors,
            'isbn' => $this->isbn,
            'workId' => $this->workId,
            'url' => $this->url,
            'coverUrl' => $this->coverUrl,
            'progress' => $this->progress,
            'rating' => $this->rating,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
        ];
    }

    public static function fromRecord(BookRecord $record): self
    {
        $authors = $record->authors !== null ? Json::decodeIfJson($record->authors) : [];

        $book = new self([
            'id' => (string)$record->id,
            'source' => self::SOURCE_OPENLIBRARY,
            'workId' => $record->externalId,
            'title' => $record->title,
            'subtitle' => $record->subtitle,
            'authors' => is_array($authors) ? array_values(array_filter($authors, 'is_string')) : [],
            'isbn' => $record->isbn,
            'url' => $record->url,
            'coverUrl' => $record->coverUrl,
            'progress' => $record->progress !== null ? (int)$record->progress : null,
            'rating' => $record->rating !== null ? (float)$record->rating : null,
            'startedAt' => $record->startedAt,
            'finishedAt' => $record->finishedAt,
            'addedAt' => $record->addedAt,
        ]);

        $book->setShelf($record->shelf);

        return $book;
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['title'], 'required'],
            [['title', 'subtitle'], 'string', 'max' => 255],
            [['progress'], 'integer', 'min' => 0, 'max' => 100],
            [['rating'], 'number', 'min' => 0, 'max' => 5],
            [['finishedAt'], 'validateDates'],
        ];
    }

    public function validateDates(string $attribute): void
    {
        if ($this->startedAt !== null && $this->finishedAt !== null && $this->finishedAt < $this->startedAt) {
            $this->addError($attribute, Craft::t('mybooks', 'A book cannot be finished before it was started.'));
        }
    }

    private static function toDate(?string $value): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Midnight in the site's timezone, so |date filters show the same day.
        $timezone = Craft::$app !== null ? Craft::$app->getTimeZone() : date_default_timezone_get();
        $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10), new DateTimeZone($timezone));

        return $date ?: null;
    }
}
