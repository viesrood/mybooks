<?php

declare(strict_types=1);

namespace viesrood\mybooks\models;

use Craft;
use craft\base\Model;

/**
 * Plugin settings. Each can also be set per environment in config/mybooks.php.
 */
class Settings extends Model
{
    /**
     * UID of the volume downloaded covers are saved to. Empty = covers are
     * linked from the book service, so every visitor's browser requests them
     * from a third party.
     */
    public ?string $coverVolume = null;

    /**
     * Folder inside the cover volume.
     */
    public string $coverSubpath = 'mybooks';

    /**
     * Seconds before a request to a book service is given up.
     */
    public int $timeout = 10;

    /**
     * The most books kept per shelf per reader. "Read" shelves can hold
     * hundreds of books; a page only ever shows a handful.
     */
    public int $maxBooksPerShelf = 50;

    /**
     * The most covers downloaded in one sync run, so a first sync of a big
     * library does not hang a console or queue run. The rest follows on the
     * next run.
     */
    public int $maxCoverDownloadsPerSync = 40;

    /**
     * @param array<string, mixed>|mixed $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        // An empty select posts "", which means "no volume".
        if (is_array($values) && array_key_exists('coverVolume', $values) && $values['coverVolume'] === '') {
            $values['coverVolume'] = null;
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['coverSubpath'], 'trim'],
            [['coverSubpath'], 'match', 'pattern' => '/^[A-Za-z0-9_\-\/]*$/', 'message' => Craft::t('mybooks', 'Use letters, digits, dashes, underscores and slashes only.')],
            [['coverVolume'], 'string'],
            [['timeout'], 'integer', 'min' => 1, 'max' => 60],
            [['maxBooksPerShelf'], 'integer', 'min' => 1, 'max' => 500],
            [['maxCoverDownloadsPerSync'], 'integer', 'min' => 0, 'max' => 500],
        ];
    }

    /**
     * The folder path inside the volume, without leading or trailing slashes.
     */
    public function getCoverFolderPath(): string
    {
        return trim($this->coverSubpath, '/');
    }
}
