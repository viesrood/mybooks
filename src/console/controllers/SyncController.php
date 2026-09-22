<?php

declare(strict_types=1);

namespace viesrood\mybooks\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use viesrood\mybooks\Plugin;
use yii\console\ExitCode;

/**
 * Syncs readers with their book services.
 *
 * Meant for cron. Quiet by design: a run in which nothing changed prints
 * nothing, so a cron log only grows when something happened.
 */
class SyncController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var string|null Only sync the reader with this handle.
     */
    public ?string $reader = null;

    /**
     * @var bool Also report readers in which nothing changed.
     */
    public bool $verbose = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['reader', 'verbose']);
    }

    /**
     * Syncs every reader, or the one given with --reader.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $readers = $plugin->getReaders()->getAllReaders();

        if ($this->reader !== null) {
            $reader = $plugin->getReaders()->getReaderByHandle($this->reader);

            if ($reader === null) {
                $this->stderr("There is no reader with the handle “{$this->reader}”.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $readers = [$reader];
        }

        $failed = false;

        foreach ($readers as $reader) {
            $outcome = $plugin->getSync()->syncReader($reader);

            if ($outcome->skipped) {
                if ($this->verbose) {
                    $this->stdout("{$reader->handle}: entered by hand, nothing to sync.\n");
                }

                continue;
            }

            if ($outcome->changed() || $this->verbose) {
                $this->stdout(sprintf(
                    "%s %s: %d added, %d updated, %d removed, %d covers.\n",
                    date('Y-m-d H:i'),
                    $reader->handle,
                    $outcome->added,
                    $outcome->updated,
                    $outcome->removed,
                    $outcome->covers,
                ));
            }

            if ($outcome->hasErrors()) {
                $failed = true;
                $this->stderr(sprintf("%s %s: %s\n", date('Y-m-d H:i'), $reader->handle, $outcome->errorSummary()), Console::FG_RED);
            }
        }

        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }
}
