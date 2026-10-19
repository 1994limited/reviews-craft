<?php

namespace nineteenninetyfour\reviews\jobs;

use Craft;
use craft\queue\BaseJob;
use nineteenninetyfour\reviews\Plugin;

/**
 * Refresh an element's cached pages once, for every "helpful" vote on its
 * reviews in the last few minutes.
 */
class RefreshAfterVotes extends BaseJob
{
    public int $elementId = 0;

    public function execute($queue): void
    {
        Plugin::getInstance()->reviews->changed($this->elementId, summary: false);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('reviews', 'Refreshing a page’s helpful votes');
    }
}
