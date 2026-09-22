<?php

declare(strict_types=1);

namespace viesrood\mybooks\web\assets\field;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Script and styles of the Books field input. Plain JavaScript on top of the
 * control panel's own Craft/Garnish globals; no build step.
 */
class FieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['field.js'];
        $this->css = ['field.css'];

        parent::init();
    }
}
