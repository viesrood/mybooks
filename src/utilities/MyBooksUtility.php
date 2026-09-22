<?php

declare(strict_types=1);

namespace viesrood\mybooks\utilities;

use Craft;
use craft\base\Utility;
use viesrood\mybooks\Plugin;

/**
 * Utilities → My Books: which Open Library accounts are linked, by which
 * entries, when they were last synced and what went wrong.
 */
class MyBooksUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('mybooks', 'My Books');
    }

    public static function id(): string
    {
        return 'mybooks';
    }

    public static function icon(): ?string
    {
        return 'book';
    }

    public static function badgeCount(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 0;
        }

        return count(array_filter(
            $plugin->getAccounts()->getAllAccounts(),
            static fn($account): bool => $account->lastError !== null,
        ));
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return '';
        }

        $accounts = $plugin->getAccounts();
        $rows = [];

        foreach ($accounts->getLinkedAccounts() as $name) {
            $rows[] = [
                'account' => $name,
                'status' => $accounts->getAccount($name),
                'elements' => $accounts->getElementsForAccount($name),
                'books' => count($plugin->getBooks()->getSyncedBooks($name)),
            ];
        }

        return Craft::$app->getView()->renderTemplate('mybooks/_utility/index', [
            'rows' => $rows,
            'coversEnabled' => $plugin->getCovers()->isEnabled(),
            'coverCount' => $plugin->getCovers()->countStored(),
        ]);
    }
}
