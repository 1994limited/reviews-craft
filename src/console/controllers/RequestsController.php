<?php

namespace nineteenninetyfour\reviews\console\controllers;

use craft\console\Controller;
use nineteenninetyfour\reviews\Plugin;
use yii\console\ExitCode;

/**
 * Review request emails.
 */
class RequestsController extends Controller
{
    /**
     * Send every request that's due. They're sent from the queue anyway;
     * this is for queues that lost a job, or sites that'd rather use cron.
     */
    public function actionSend(): int
    {
        $sent = Plugin::getInstance()->requests->sendDue();
        $this->stdout("Sent {$sent} review request emails.\n");

        return ExitCode::OK;
    }
}
