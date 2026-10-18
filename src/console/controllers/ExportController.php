<?php

namespace nineteenninetyfour\reviews\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use nineteenninetyfour\reviews\elements\Review;
use yii\console\ExitCode;

/**
 * Exports every review as CSV, in the format Reviews (for Craft or
 * Statamic) imports.
 */
class ExportController extends Controller
{
    public $defaultAction = 'index';

    public const COLUMNS = ['id', 'entry', 'entry_title', 'rating', 'title', 'body', 'name', 'email', 'status', 'verified', 'reply', 'replied_at', 'created_at', 'source'];

    /**
     * `php craft reviews/export reviews.csv`
     */
    public function actionIndex(string $file = 'php://stdout'): int
    {
        $out = fopen($file, 'w');

        if ($out === false) {
            $this->stderr("Can't write {$file}.\n", Console::FG_RED);

            return ExitCode::CANTCREAT;
        }

        fputcsv($out, self::COLUMNS, ',', '"', '');
        $count = 0;

        foreach (Review::find()->status(null)->orderBy(['elements.id' => SORT_ASC])->each(200) as $review) {
            /** @var Review $review */
            fputcsv($out, [
                $review->uid,
                $review->elementId,
                $review->getElement()?->title,
                $review->rating,
                $review->title,
                $review->body,
                $review->name,
                $review->email,
                $review->getStatus(),
                $review->verified ? '1' : '0',
                $review->reply,
                $review->dateReplied?->format(DATE_ATOM),
                $review->dateCreated?->format(DATE_ATOM),
                $review->source,
            ], ',', '"', '');
            $count++;
        }

        fclose($out);

        if ($file !== 'php://stdout') {
            $this->stdout("Exported {$count} reviews to {$file}.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}
