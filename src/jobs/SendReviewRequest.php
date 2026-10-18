<?php

namespace nineteenninetyfour\reviews\jobs;

use Craft;
use craft\queue\BaseJob;
use nineteenninetyfour\reviews\Plugin;

/**
 * Send one review request email, once it's due.
 */
class SendReviewRequest extends BaseJob
{
    public int $requestId = 0;

    public function execute($queue): void
    {
        Plugin::getInstance()->requests->send($this->requestId);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('reviews', 'Sending a review request');
    }
}
