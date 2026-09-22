<?php

declare(strict_types=1);

namespace viesrood\mybooks\widgets;

use Craft;
use craft\base\Widget;
use viesrood\mybooks\enums\Shelf;
use viesrood\mybooks\Plugin;

/**
 * Dashboard widget: what everyone with a Books field is reading.
 */
class ReadingWidget extends Widget
{
    public string $shelf = 'reading';

    public int $limit = 4;

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
        return (Shelf::tryFromAny($this->shelf) ?? Shelf::Reading)->label();
    }

    public function getBodyHtml(): ?string
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return null;
        }

        $shelf = Shelf::tryFromAny($this->shelf) ?? Shelf::Reading;
        $groups = [];

        foreach ($plugin->getAccounts()->getLibraries() as $item) {
            $books = $item['library']->shelf($shelf, max(1, $this->limit));

            if ($books !== []) {
                $groups[] = ['name' => $item['title'], 'books' => $books];
            }
        }

        return Craft::$app->getView()->renderTemplate('mybooks/_widget/body', [
            'groups' => $groups,
            'shelf' => $shelf,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('mybooks/_widget/settings', [
            'widget' => $this,
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
}
