<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\Json;
use DateTime;
use DateTimeZone;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\records\BookRecord;

/**
 * A book on one of a reader's shelves.
 *
 * In Twig: book.title, book.authorsString, book.cover (Asset|null),
 * book.coverSrc, book.progress, book.rating, book.startedDate, ...
 */
class Book extends Model
{
    public ?int $id = null;

    public ?string $uid = null;

    public ?int $readerId = null;

    public string $provider = '';

    public string $externalId = '';

    public string $title = '';

    public ?string $subtitle = null;

    /** @var string[] */
    public array $authors = [];

    public ?string $isbn = null;

    public ?string $url = null;

    public ?string $coverUrl = null;

    public ?int $coverAssetId = null;

    public ?string $coverSourceUrl = null;

    public ?int $progress = null;

    public ?float $rating = null;

    /** Y-m-d */
    public ?string $startedAt = null;

    /** Y-m-d */
    public ?string $finishedAt = null;

    /** Y-m-d */
    public ?string $addedAt = null;

    public int $sortOrder = 0;

    public ?string $hash = null;

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

    public function getReader(): ?Reader
    {
        return $this->readerId !== null
            ? Plugin::getInstance()?->getReaders()->getReaderById($this->readerId)
            : null;
    }

    /**
     * The locally stored cover, if there is one. Use this with image
     * transforms (or ImgixKit); it never touches the book service.
     */
    public function getCover(): ?Asset
    {
        if ($this->cover === null) {
            $this->cover = $this->coverAssetId !== null
                ? (Asset::find()->id($this->coverAssetId)->status(null)->one() ?? false)
                : false;
        }

        return $this->cover ?: null;
    }

    /**
     * Pre-load the cover asset (used for eager loading a whole shelf).
     */
    public function setCover(?Asset $asset): void
    {
        $this->cover = $asset ?? false;
    }

    /**
     * A URL for an <img>: the local asset when there is one, otherwise the
     * cover at the book service (which means the visitor's browser contacts
     * that service; configure a cover volume to prevent that).
     *
     * @param mixed $transform Passed on to Asset::getUrl().
     */
    public function getCoverSrc(mixed $transform = null): ?string
    {
        $asset = $this->getCover();

        if ($asset !== null) {
            return $asset->getUrl($transform);
        }

        return $this->coverUrl;
    }

    public function hasCover(): bool
    {
        return $this->getCover() !== null || $this->coverUrl !== null;
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
        return $this->provider === \viesrood\mybooks\providers\Manual::handle();
    }

    public static function fromRecord(BookRecord $record): self
    {
        $authors = $record->authors !== null ? Json::decodeIfJson($record->authors) : [];

        $book = new self([
            'id' => (int)$record->id,
            'uid' => $record->uid,
            'readerId' => (int)$record->readerId,
            'provider' => $record->provider,
            'externalId' => $record->externalId,
            'title' => $record->title,
            'subtitle' => $record->subtitle,
            'authors' => is_array($authors) ? array_values(array_filter($authors, 'is_string')) : [],
            'isbn' => $record->isbn,
            'url' => $record->url,
            'coverUrl' => $record->coverUrl,
            'coverAssetId' => $record->coverAssetId !== null ? (int)$record->coverAssetId : null,
            'coverSourceUrl' => $record->coverSourceUrl,
            'progress' => $record->progress !== null ? (int)$record->progress : null,
            'rating' => $record->rating !== null ? (float)$record->rating : null,
            'startedAt' => $record->startedAt,
            'finishedAt' => $record->finishedAt,
            'addedAt' => $record->addedAt,
            'sortOrder' => (int)$record->sortOrder,
            'hash' => $record->hash,
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
            [['title', 'subtitle', 'isbn', 'url'], 'trim'],
            [['title'], 'required'],
            [['title', 'subtitle'], 'string', 'max' => 255],
            [['url'], 'url', 'defaultScheme' => 'https'],
            [['url'], 'string', 'max' => 2000],
            [['isbn'], 'validateIsbn'],
            [['progress'], 'integer', 'min' => 0, 'max' => 100],
            [['rating'], 'number', 'min' => 0, 'max' => 5],
            [['finishedAt'], 'validateDates'],
        ];
    }

    public function validateIsbn(string $attribute): void
    {
        if ($this->isbn === null || $this->isbn === '') {
            $this->isbn = null;

            return;
        }

        $isbn = BookData::isbn($this->isbn);

        if ($isbn === null) {
            $this->addError($attribute, Craft::t('mybooks', 'An ISBN has 10 or 13 digits.'));

            return;
        }

        $this->isbn = $isbn;
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
        $date = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10), new DateTimeZone(Craft::$app->getTimeZone()));

        return $date ?: null;
    }
}
