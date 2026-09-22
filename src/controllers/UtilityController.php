<?php

declare(strict_types=1);

namespace viesrood\mybooks\controllers;

use Craft;
use craft\web\Controller;
use viesrood\mybooks\Plugin;
use yii\web\Response;

/**
 * "Sync all" on the Utilities → My Books screen.
 */
class UtilityController extends Controller
{
    public function actionSyncAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:mybooks');

        $outcomes = Plugin::getInstance()?->getSync()->syncAll() ?? [];
        $errors = [];
        $changes = 0;

        foreach ($outcomes as $account => $outcome) {
            $changes += $outcome->added + $outcome->updated + $outcome->removed + $outcome->covers;

            if ($outcome->hasErrors()) {
                $errors[] = $account . ': ' . $outcome->errorSummary();
            }
        }

        if ($errors !== []) {
            return $this->asFailure(implode(' ', $errors));
        }

        return $this->asSuccess($changes > 0
            ? Craft::t('mybooks', 'Synced: {count, plural, =1{one change} other{# changes}}.', ['count' => $changes])
            : Craft::t('mybooks', 'Synced: everything was already up to date.'));
    }
}
