<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\TestCase;
use viesrood\mybooks\models\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $settings = new Settings();

        self::assertNull($settings->coverVolume);
        self::assertSame('mybooks', $settings->getCoverFolderPath());
        self::assertTrue($settings->validate(), print_r($settings->getErrors(), true));
    }

    public function testAnEmptyVolumeSelectionMeansNoVolume(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['coverVolume' => ''], false);

        self::assertNull($settings->coverVolume);
    }

    public function testRejectsOutOfRangeValuesAndOddFolders(): void
    {
        $settings = new Settings();
        $settings->timeout = 0;
        $settings->maxBooksPerShelf = 0;
        $settings->maxCoverDownloadsPerSync = -1;
        $settings->coverSubpath = '../../etc';

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('timeout', $settings->getErrors());
        self::assertArrayHasKey('maxBooksPerShelf', $settings->getErrors());
        self::assertArrayHasKey('maxCoverDownloadsPerSync', $settings->getErrors());
        self::assertArrayHasKey('coverSubpath', $settings->getErrors());
    }

    public function testFolderPathIsTrimmedOfSlashes(): void
    {
        $settings = new Settings();
        $settings->coverSubpath = '/boeken/covers/';

        self::assertSame('boeken/covers', $settings->getCoverFolderPath());
    }
}
