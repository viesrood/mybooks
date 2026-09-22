<?php

declare(strict_types=1);

namespace viesrood\mybooks\controllers;

use Craft;
use craft\web\Controller;
use viesrood\mybooks\base\ProviderException;
use viesrood\mybooks\models\BookData;
use viesrood\mybooks\Plugin;
use viesrood\mybooks\providers\OpenLibrary;
use yii\web\Response;

/**
 * The AJAX actions behind the Books field input. Only for logged-in control
 * panel users; nothing here writes content (a sync only refreshes the cache).
 */
class FieldController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return true;
    }

    /**
     * Searches Open Library by title, author or ISBN.
     */
    public function actionSearch(): Response
    {
        $query = (string)$this->request->getRequiredBodyParam('q');

        try {
            $books = (new OpenLibrary())->search($query);
        } catch (ProviderException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'books' => array_map(static fn(BookData $book): array => [
                'workId' => $book->externalId,
                'title' => $book->title,
                'subtitle' => $book->subtitle,
                'authors' => $book->authors,
                'isbn' => $book->isbn,
                'url' => $book->url,
                'coverUrl' => $book->coverUrl,
            ], $books),
        ]);
    }

    public function actionTestAccount(): Response
    {
        $account = OpenLibrary::normalizeUsername((string)$this->request->getBodyParam('account'));

        if ($account === '') {
            return $this->asFailure(Craft::t('mybooks', 'Enter an Open Library username.'));
        }

        try {
            return $this->asSuccess((new OpenLibrary())->testConnection($account));
        } catch (ProviderException $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    /**
     * Syncs a linked account right away. Only accounts that some field
     * already links to, so this cannot be used to fill the cache with
     * arbitrary accounts.
     */
    public function actionSyncAccount(): Response
    {
        $plugin = Plugin::getInstance();
        $account = OpenLibrary::normalizeUsername((string)$this->request->getBodyParam('account'));

        if ($plugin === null || $account === '' || $plugin->getAccounts()->getAccount($account) === null) {
            return $this->asFailure(Craft::t('mybooks', 'Save the entry first; the account is synced right after.'));
        }

        $outcome = $plugin->getSync()->syncAccount($account);

        if ($outcome->hasErrors()) {
            return $this->asFailure((string)$outcome->errorSummary());
        }

        $changes = $outcome->added + $outcome->updated + $outcome->removed + $outcome->covers;

        return $this->asSuccess($changes > 0
            ? Craft::t('mybooks', 'Synced: {count, plural, =1{one change} other{# changes}}.', ['count' => $changes])
            : Craft::t('mybooks', 'Synced: everything was already up to date.'));
    }
}
