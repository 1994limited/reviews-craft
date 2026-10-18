<?php

namespace nineteenninetyfour\reviews\jobs;

use Craft;
use craft\queue\BaseJob;
use nineteenninetyfour\reviews\Plugin;

/**
 * Sync one source in the queue.
 */
class SyncSource extends BaseJob
{
    public int $sourceId = 0;

    public function execute($queue): void
    {
        $source = Plugin::getInstance()->sync->find($this->sourceId);

        if (! $source || ! $source->enabled) {
            return;
        }

        $report = Plugin::getInstance()->sync->run($source);

        Craft::info(sprintf('Reviews synced %s: %d added, %d updated, %d not matched.', $source->name, $report->added, $report->updated, count($report->unmatched)), 'reviews');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('reviews', 'Syncing reviews');
    }
}
