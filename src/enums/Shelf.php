<?php

declare(strict_types=1);

namespace viesrood\mybooks\enums;

use Craft;

/**
 * The three shelves every provider maps onto.
 *
 * The values are what ends up in the database and in project config, so they
 * never change; labels are translated at render time.
 */
enum Shelf: string
{
    case Want = 'want';
    case Reading = 'reading';
    case Read = 'read';

    /**
     * Accepts the enum itself, its value, or the names other services use
     * ("currently-reading", "want-to-read", "already-read", ...). Returns null
     * for anything else, so a typo in a template renders nothing instead of a 500.
     */
    public static function tryFromAny(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'want', 'want-to-read', 'wanttoread', 'to-read', 'tbr' => self::Want,
            'reading', 'currently-reading', 'currentlyreading', 'current', 'now' => self::Reading,
            'read', 'already-read', 'alreadyread', 'finished', 'done' => self::Read,
            default => null,
        };
    }

    /**
     * @param mixed $values An array or a comma-separated string.
     * @return self[] Unique shelves in canonical order.
     */
    public static function listFrom(mixed $values): array
    {
        if (is_string($values)) {
            $values = explode(',', $values);
        }

        if (!is_array($values)) {
            return [];
        }

        $found = [];

        foreach ($values as $value) {
            $shelf = self::tryFromAny($value);

            if ($shelf !== null) {
                $found[$shelf->value] = true;
            }
        }

        return array_values(array_filter(
            self::cases(),
            static fn(self $shelf): bool => isset($found[$shelf->value]),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Want => Craft::t('mybooks', 'Want to read'),
            self::Reading => Craft::t('mybooks', 'Currently reading'),
            self::Read => Craft::t('mybooks', 'Read'),
        };
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn(self $shelf): array => ['label' => $shelf->label(), 'value' => $shelf->value],
            self::cases(),
        );
    }
}
