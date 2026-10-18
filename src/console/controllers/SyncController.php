<?php

namespace nineteenninetyfour\reviews\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use nineteenninetyfour\reviews\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Syncs reviews from other platforms. Run it from cron, hourly say: each
 * source syncs once its own interval has passed.
 */
class SyncController extends Controller
{
    public $defaultAction = 'index';

    /** Sync every enabled source now, whether it's due or not. */
    public bool $force = false;

    /** Fetch everything again, not only what's changed. */
    public bool $all = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force', 'all']);
    }

    /**
     * `php craft reviews/sync`, or `php craft reviews/sync 3` for one source.
     */
    public function actionIndex(?int $source = null): int
    {
        $sync = Plugin::getInstance()->sync;
        $failed = 0;

        foreach ($sync->all() as $item) {
            if ($source !== null ? $item->id !== $source : (! $item->enabled || (! $this->force && ! $item->isDue()))) {
                continue;
            }

            try {
                $report = $sync->run($item, $this->all);
                $this->stdout("{$item->name}: {$report->added} added, {$report->updated} updated, ".count($report->unmatched)." matched nothing.\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $failed++;
                $this->stderr("{$item->name}: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        return $failed ? ExitCode::UNAVAILABLE : ExitCode::OK;
    }
}
