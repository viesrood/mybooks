<?php

declare(strict_types=1);

namespace viesrood\mybooks\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;
use yii\db\Schema;

/**
 * Links an element (a team member entry, a user) to a reader.
 *
 * Stores the reader's uid, which is stable across environments because
 * readers live in project config.
 *
 * {% set reader = entry.reader %}
 * {% for book in reader.shelf('reading', 3) ?? [] %}
 */
class ReaderField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('mybooks', 'Reader (My Books)');
    }

    public static function icon(): string
    {
        return 'book';
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', Reader::class);
    }

    public static function dbType(): string
    {
        return Schema::TYPE_STRING;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof Reader) {
            return $value;
        }

        if (!is_string($value) || $value === '' || $value === '__blank__') {
            return null;
        }

        // A reader that was deleted simply becomes "no reader".
        return Plugin::getInstance()?->getReaders()->getReaderByUid($value);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof Reader ? $value->uid : null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $readers = Plugin::getInstance()?->getReaders();
        $options = $readers?->getReaderOptions() ?? [];

        if ($options === []) {
            return Html::tag('p', Craft::t('mybooks', 'There are no readers yet. Add one under My Books.'), ['class' => 'light']);
        }

        array_unshift($options, ['label' => Craft::t('mybooks', 'No reader'), 'value' => '']);

        return Cp::selectHtml([
            'id' => $this->getInputId(),
            'describedBy' => $this->describedBy,
            'name' => $this->handle,
            'options' => $options,
            'value' => $value instanceof Reader ? $value->uid : '',
        ]);
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return $value instanceof Reader ? $value->name : '';
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return $value instanceof Reader ? Html::encode($value->name) : '';
    }
}
