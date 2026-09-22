<?php

declare(strict_types=1);

namespace viesrood\mybooks\widgets;

use Craft;
use craft\base\Widget;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;

/**
 * Dashboard widget: the covers on one shelf of one reader, or of everyone.
 */
class ReadingWidget extends Widget
{
    /** Reader uid; empty = every reader. */
    public ?string $reader = null;

    public string $shelf = 'reading';

    public int $limit = 6;

    public static function displayName(): string
    {
        return Craft::t('mybooks', 'Reading');
    }

    public static function icon(): ?string
    {
        return 'book';
    }

    protected static function allowMultipleInstances(): bool
    {
        return true;
    }

    public function getTitle(): ?string
    {
        $shelf = Shelf::tryFromAny($this->shelf) ?? Shelf::Reading;
        $reader = $this->getReader();

        return $reader !== null ? $reader->name . ': ' . $shelf->label() : $shelf->label();
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return null;
        }

        $shelf = Shelf::tryFromAny($this->shelf) ?? Shelf::Reading;
        $reader = $this->getReader();
        $readers = $reader !== null ? [$reader] : $plugin->getReaders()->getAllReaders();
        $groups = [];

        foreach ($readers as $item) {
            $books = $plugin->getBooks()->getBooks($item, $shelf, max(1, $this->limit));

            if ($books !== []) {
                $groups[] = ['reader' => $item, 'books' => $books];
            }
        }

        return Craft::$app->getView()->renderTemplate('mybooks/_widget/body', [
            'groups' => $groups,
            'shelf' => $shelf,
            'showNames' => $reader === null,
            'hasReaders' => $readers !== [],
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        $plugin = Plugin::getInstance();
        $readerOptions = $plugin?->getReaders()->getReaderOptions() ?? [];
        array_unshift($readerOptions, ['label' => Craft::t('mybooks', 'Everyone'), 'value' => '']);

        return Craft::$app->getView()->renderTemplate('mybooks/_widget/settings', [
            'widget' => $this,
            'readerOptions' => $readerOptions,
            'shelfOptions' => Shelf::options(),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1, 'max' => 24];
        $rules[] = [['shelf'], 'in', 'range' => array_map(static fn(Shelf $s): string => $s->value, Shelf::cases())];

        return $rules;
    }

    private function getReader(): ?Reader
    {
        if ($this->reader === null || $this->reader === '') {
            return null;
        }

        return Plugin::getInstance()?->getReaders()->getReaderByUid($this->reader);
    }
}
