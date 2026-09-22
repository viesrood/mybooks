<?php

declare(strict_types=1);

namespace viesrood\mybooks\tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the message catalogues: every language has the same keys, no value is
 * empty, and every string the code actually asks for exists.
 */
final class TranslationsTest extends TestCase
{
    private const LANGUAGES = ['en', 'nl'];

    /**
     * @return array<string, string>
     */
    private static function messages(string $language): array
    {
        /** @var array<string, string> $messages */
        $messages = require dirname(__DIR__) . '/src/translations/' . $language . '/mybooks.php';

        return $messages;
    }

    public function testEveryLanguageHasTheSameKeys(): void
    {
        $english = array_keys(self::messages('en'));

        foreach (self::LANGUAGES as $language) {
            if ($language === 'en') {
                continue;
            }

            $keys = array_keys(self::messages($language));

            self::assertSame([], array_diff($english, $keys), sprintf('%s is missing keys', $language));
            self::assertSame([], array_diff($keys, $english), sprintf('%s has unknown keys', $language));
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach (self::LANGUAGES as $language) {
            foreach (self::messages($language) as $key => $value) {
                self::assertNotSame('', trim($value), sprintf('%s: "%s" is empty', $language, $key));
            }
        }
    }

    public function testEveryTranslatedStringInTheSourceIsInTheCatalogue(): void
    {
        $known = self::messages('en');
        $missing = [];

        foreach (self::sourceFiles() as $file) {
            $contents = (string)file_get_contents($file);

            foreach (self::extractMessages($contents) as $message) {
                if (!array_key_exists($message, $known)) {
                    $missing[$message] = basename($file);
                }
            }
        }

        self::assertSame([], $missing, 'Untranslated strings: ' . print_r($missing, true));
    }

    /**
     * @return string[]
     */
    private static function extractMessages(string $contents): array
    {
        $messages = [];

        // Craft::t('mybooks', '...') and 'value' => ... inside registerTranslations()
        if (preg_match_all("/Craft::t\(\s*'mybooks',\s*'((?:\\\\'|[^'])*)'/", $contents, $matches) !== false) {
            $messages = array_merge($messages, $matches[1]);
        }

        // '...'|t('mybooks') in Twig templates
        if (preg_match_all("/'((?:\\\\'|[^'])*)'\|t\('mybooks'\)/", $contents, $matches) !== false) {
            $messages = array_merge($messages, $matches[1]);
        }

        return array_map(
            static fn(string $message): string => str_replace("\\'", "'", $message),
            $messages,
        );
    }

    /**
     * @return string[]
     */
    private static function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, '/translations/')) {
                continue;
            }

            if (in_array($file->getExtension(), ['php', 'twig'], true)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
