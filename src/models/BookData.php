<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use viesrood\mybooks\enums\Shelf;

/**
 * What a provider hands back for one book: plain, already-sanitised values.
 *
 * Providers build these with {@see BookData::create()}, which normalises every
 * field, so the sync service never has to second-guess a remote API.
 */
final class BookData
{
    /**
     * @param string[] $authors
     */
    private function __construct(
        public readonly string $externalId,
        public readonly Shelf $shelf,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly array $authors,
        public readonly ?string $isbn,
        public readonly ?string $url,
        public readonly ?string $coverUrl,
        public readonly ?int $progress,
        public readonly ?float $rating,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly ?string $addedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function create(string $externalId, Shelf $shelf, array $values): self
    {
        $title = self::text($values['title'] ?? null);

        return new self(
            externalId: $externalId,
            shelf: $shelf,
            title: $title ?? '',
            subtitle: self::text($values['subtitle'] ?? null),
            authors: self::authors($values['authors'] ?? []),
            isbn: self::isbn($values['isbn'] ?? null),
            url: self::httpUrl($values['url'] ?? null),
            coverUrl: self::httpUrl($values['coverUrl'] ?? null),
            progress: self::progress($values['progress'] ?? null),
            rating: self::rating($values['rating'] ?? null),
            startedAt: self::date($values['startedAt'] ?? null),
            finishedAt: self::date($values['finishedAt'] ?? null),
            addedAt: self::date($values['addedAt'] ?? null),
        );
    }

    /**
     * A stable fingerprint of everything the sync stores. When it matches the
     * stored hash, the row is left alone, so a quiet sync writes nothing.
     */
    public function hash(): string
    {
        return sha1((string)json_encode([
            $this->shelf->value,
            $this->title,
            $this->subtitle,
            $this->authors,
            $this->isbn,
            $this->url,
            $this->coverUrl,
            $this->progress,
            $this->rating,
            $this->startedAt,
            $this->finishedAt,
            $this->addedAt,
        ]));
    }

    public static function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        // Collapse whitespace and strip control characters; remote titles
        // occasionally carry stray newlines or tabs.
        $clean = trim((string)preg_replace('/[\p{Cc}\s]+/u', ' ', (string)$value));

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, 255);
    }

    /**
     * @return string[] Unique, non-empty author names in the original order.
     */
    public static function authors(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $authors = [];

        foreach ($value as $name) {
            $name = self::text($name);

            if ($name !== null && !in_array($name, $authors, true)) {
                $authors[] = $name;
            }
        }

        return $authors;
    }

    /**
     * Only http(s) URLs survive, so a remote value can never become a
     * javascript: link or a local file path.
     */
    public static function httpUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || strlen($value) > 2000) {
            return null;
        }

        return $value;
    }

    public static function isbn(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $digits = strtoupper((string)preg_replace('/[^0-9Xx]/', '', (string)$value));

        return in_array(strlen($digits), [10, 13], true) ? $digits : null;
    }

    public static function progress(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int)round((float)$value)));
    }

    /**
     * A rating from 0 to 5 in half steps; zero means "not rated".
     */
    public static function rating(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $rating = round((float)$value * 2) / 2;

        if ($rating <= 0) {
            return null;
        }

        return min(5.0, $rating);
    }

    /**
     * Normalises the date formats the providers use to Y-m-d. The time of day
     * is dropped on purpose: "started on" is a calendar date, and keeping it
     * as a date avoids every timezone shift between UTC and the site.
     */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        // Open Library: "2024/03/14, 09:12:44"; Hardcover: "2024-03-14".
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', trim($value), $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
