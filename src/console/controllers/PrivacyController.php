<?php

namespace nineteenninetyfour\reviews\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;
use yii\console\ExitCode;

/**
 * Requests under data protection law: what Reviews holds about someone,
 * and forgetting them.
 */
class PrivacyController extends Controller
{
    /** Delete their reviews, rather than taking their name and email off. */
    public bool $delete = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'forget' ? ['delete'] : []);
    }

    /**
     * Everything Reviews holds about an email address, as JSON.
     */
    public function actionExport(string $email): int
    {
        $reviews = [];

        foreach (Review::find()->status(null)->email(mb_strtolower($email))->all() as $review) {
            $reviews[] = [
                'of' => $review->getElement()?->title,
                'rating' => $review->rating,
                'ratings' => $review->ratings,
                'title' => $review->title,
                'body' => $review->body,
                'name' => $review->name,
                'email' => $review->email,
                'status' => $review->getStatus(),
                'written' => $review->dateCreated?->format(DATE_ATOM),
                'reply' => $review->reply,
            ];
        }

        $this->stdout(Json::encode([
            'reviews' => $reviews,
            'optedOutOfRequestEmails' => Plugin::getInstance()->requests->optedOut($email),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return ExitCode::OK;
    }

    /**
     * Take someone's name and email off their reviews (keeping what they
     * wrote), or with --delete, delete them.
     */
    public function actionForget(string $email): int
    {
        if ($this->delete && $this->interactive && ! $this->confirm("Delete every review by {$email}?")) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->reviews->forget($email, null, $this->delete);
        $this->stdout(($this->delete ? 'Deleted' : 'Anonymised')." {$count} reviews.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
