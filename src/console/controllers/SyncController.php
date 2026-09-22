<?php

declare(strict_types=1);

namespace viesrood\mybooks\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use viesrood\mybooks\models\SyncOutcome;
use viesrood\mybooks\Plugin;
use yii\console\ExitCode;

/**
 * Syncs linked Open Library accounts and stores covers locally.
 *
 * Meant for cron. Quiet by design: a run in which nothing changed prints
 * nothing, so a cron log only grows when something happened.
 */
class SyncController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var string|null Only sync this Open Library account (skips clean-up).
     */
    public ?string $account = null;

    /**
     * @var bool Also report accounts in which nothing changed.
     */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['account', 'verbose']);
    }

    /**
     * Syncs every linked account, stores missing covers of hand-picked books,
     * removes accounts no field links to any more and deletes unused covers.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $outcomes = $this->account !== null
            ? [$this->account => $plugin->getSync()->syncAccount($this->account)]
            : $plugin->getSync()->syncAll();

        $failed = false;

        foreach ($outcomes as $account => $outcome) {
            $failed = $this->report((string)$account, $outcome) || $failed;
        }

        if ($outcomes === [] && $this->verbose) {
            $this->stdout("No Books field links to an Open Library account.\n");
        }

        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }

    /**
     * @return bool Whether the account failed.
     */
    private function report(string $account, SyncOutcome $outcome): bool
    {
        if ($outcome->skipped) {
            $this->stderr("“{$account}” is not a valid Open Library username.\n", Console::FG_RED);

            return true;
        }

        if ($outcome->changed() || $this->verbose) {
            $this->stdout(sprintf(
                "%s %s: %d added, %d updated, %d removed, %d covers.\n",
                date('Y-m-d H:i'),
                $account,
                $outcome->added,
                $outcome->updated,
                $outcome->removed,
                $outcome->covers,
            ));
        }

        if ($outcome->hasErrors()) {
            $this->stderr(sprintf("%s %s: %s\n", date('Y-m-d H:i'), $account, $outcome->errorSummary()), Console::FG_RED);

            return true;
        }

        return false;
    }
}
