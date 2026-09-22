<?php

declare(strict_types=1);

namespace viesrood\mybooks\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use viesrood\mybooks\base\ProviderException;
use viesrood\mybooks\jobs\SyncReader;
use viesrood\mybooks\models\Reader;
use viesrood\mybooks\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel screens for readers.
 *
 * Reader settings are project config, so creating, editing and deleting is
 * admin-only and needs allowAdminChanges. Syncing is runtime work and is open
 * to anyone with the "manage books" permission.
 */
class ReadersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $user = Craft::$app->getUser();

        if (!$user->getIsAdmin() && !$user->checkPermission(Plugin::PERMISSION_MANAGE_BOOKS)) {
            throw new ForbiddenHttpException('User is not permitted to perform this action.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = $this->plugin();

        return $this->renderTemplate('mybooks/readers/_index', [
            'readers' => $plugin->getReaders()->getAllReaders(),
            'counts' => $plugin->getReaders()->getBookCounts(),
            'coversEnabled' => $plugin->getCovers()->isEnabled(),
            'canEdit' => $this->canEditReaders(),
        ]);
    }

    public function actionEdit(?int $readerId = null, ?Reader $reader = null): Response
    {
        $this->requireEditableReaders();
        $plugin = $this->plugin();

        if ($reader === null) {
            if ($readerId !== null) {
                $reader = $plugin->getReaders()->getReaderById($readerId);

                if ($reader === null) {
                    throw new NotFoundHttpException('Reader not found');
                }
            } else {
                $reader = new Reader();
            }
        }

        $providers = [];

        foreach ($plugin->getProviders()->getAllProviders() as $handle => $provider) {
            $providers[$handle] = [
                'name' => $provider::displayName(),
                'sync' => $provider->supportsSync(),
                'account' => $provider->requiresAccount(),
                'token' => $provider->requiresToken(),
            ];
        }

        return $this->renderTemplate('mybooks/readers/_edit', [
            'reader' => $reader,
            'isNew' => $reader->id === null,
            'providerOptions' => $plugin->getProviders()->getProviderOptions(),
            'providers' => $providers,
            'shelfOptions' => \viesrood\mybooks\enums\Shelf::options(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireEditableReaders();
        $plugin = $this->plugin();

        $readerId = $this->request->getBodyParam('readerId');
        $reader = $readerId ? $plugin->getReaders()->getReaderById((int)$readerId) : new Reader();

        if ($reader === null) {
            throw new NotFoundHttpException('Reader not found');
        }

        $this->populate($reader);

        if (!$plugin->getReaders()->saveReader($reader)) {
            return $this->asModelFailure($reader, Craft::t('mybooks', 'Couldn’t save the reader.'), 'reader');
        }

        // Fill the shelves right away instead of waiting for the next cron run.
        if ($reader->id !== null && !$reader->isManual()) {
            Craft::$app->getQueue()->push(new SyncReader(['readerId' => $reader->id]));
        }

        return $this->asModelSuccess($reader, Craft::t('mybooks', 'Reader saved.'), 'reader', [], 'mybooks');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireEditableReaders();

        $reader = $this->plugin()->getReaders()->getReaderById((int)$this->request->getRequiredBodyParam('id'));

        if ($reader === null || !$this->plugin()->getReaders()->deleteReader($reader)) {
            return $this->asFailure(Craft::t('mybooks', 'Couldn’t delete the reader.'));
        }

        return $this->asSuccess();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireEditableReaders();

        /** @var array<int|string> $ids */
        $ids = Json::decode((string)$this->request->getRequiredBodyParam('ids'));
        $this->plugin()->getReaders()->reorderReaders(array_map('strval', $ids));

        return $this->asSuccess();
    }

    /**
     * Syncs one reader, or all of them, right now. Synchronous on purpose:
     * whoever clicks wants to see the result, including any error.
     */
    public function actionSync(): ?Response
    {
        $this->requirePostRequest();
        $plugin = $this->plugin();
        $readerId = $this->request->getBodyParam('readerId');

        $readers = $readerId
            ? array_filter([$plugin->getReaders()->getReaderById((int)$readerId)])
            : $plugin->getReaders()->getAllReaders();

        $errors = [];
        $changes = 0;

        foreach ($readers as $reader) {
            $outcome = $plugin->getSync()->syncReader($reader);
            $changes += $outcome->added + $outcome->updated + $outcome->removed + $outcome->covers;

            if ($outcome->hasErrors()) {
                $errors[] = $reader->name . ': ' . $outcome->errorSummary();
            }
        }

        if ($errors !== []) {
            return $this->asFailure(implode(' ', $errors));
        }

        return $this->asSuccess($changes > 0
            ? Craft::t('mybooks', 'Synced: {count, plural, =1{one change} other{# changes}}.', ['count' => $changes])
            : Craft::t('mybooks', 'Synced: everything was already up to date.'));
    }

    /**
     * Tests the settings in the form without saving them.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireEditableReaders();

        $reader = new Reader();
        $this->populate($reader);
        $provider = $reader->getProviderInstance();

        if ($provider === null) {
            return $this->asFailure(Craft::t('mybooks', 'Choose a book service.'));
        }

        try {
            return $this->asSuccess($provider->testConnection($reader));
        } catch (ProviderException $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    private function populate(Reader $reader): void
    {
        $reader->name = (string)$this->request->getBodyParam('name', $reader->name);
        $reader->handle = (string)$this->request->getBodyParam('handle', $reader->handle);
        $reader->provider = (string)$this->request->getBodyParam('provider', $reader->provider);
        $reader->account = $this->request->getBodyParam('account', $reader->account) ?: null;
        $reader->token = $this->request->getBodyParam('token', $reader->token) ?: null;
        $reader->setShelves($this->request->getBodyParam('shelves') ?: []);
    }

    private function canEditReaders(): bool
    {
        return Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    private function requireEditableReaders(): void
    {
        $this->requireAdmin(true);
    }

    private function plugin(): Plugin
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            throw new NotFoundHttpException();
        }

        return $plugin;
    }
}
