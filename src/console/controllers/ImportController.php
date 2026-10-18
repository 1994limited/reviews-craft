<?php

namespace nineteenninetyfour\reviews\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use nineteenninetyfour\reviews\models\ImportOptions;
use nineteenninetyfour\reviews\Plugin;
use yii\console\ExitCode;

/**
 * Imports reviews from a CSV or JSON file another platform exported.
 */
class ImportController extends Controller
{
    public $defaultAction = 'index';

    /** The format, if it can't be worked out: yotpo, judgeme, reviewsio, shopify, trustpilot, woocommerce, reviews or generic. */
    public ?string $format = null;

    /** Count them as this site's own reviews (only for reviews your customers wrote for you). */
    public bool $counted = false;

    /** Hold every review for an editor. */
    public bool $pending = false;

    /** Include reviews the platform wasn't showing, as rejected. */
    public bool $includeUnpublished = false;

    /** The entry ID reviews of the business go on. */
    public ?int $business = null;

    /** The site ID they were written on. */
    public ?int $site = null;

    /** Only say what would be imported. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['format', 'counted', 'pending', 'includeUnpublished', 'business', 'site', 'dryRun']);
    }

    /**
     * Import a file: `php craft reviews/import exports/yotpo.csv --dry-run`.
     */
    public function actionIndex(string $file): int
    {
        if (! is_file($file) || ! is_readable($file)) {
            $this->stderr("Can't read {$file}.\n", Console::FG_RED);

            return ExitCode::NOINPUT;
        }

        $imports = Plugin::getInstance()->imports;
        $result = $imports->read((string) file_get_contents($file), $this->format);

        if (! $result->format) {
            $this->stderr(($result->skipped[0]['reason'] ?? 'The file couldn’t be read.')."\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $this->stdout("Read as {$result->format->label()}: ".count($result->reviews).' reviews, '.count($result->skipped)." rows skipped.\n");

        $options = new ImportOptions([
            'counted' => $this->counted,
            'status' => $this->pending ? 'pending' : 'keep',
            'includeUnpublished' => $this->includeUnpublished,
            'businessElementId' => $this->business,
            'siteId' => $this->site,
        ]);

        if ($this->dryRun) {
            $preview = $imports->preview($result->reviews, $options);
            $this->stdout("Would add {$preview['matched']}; {$preview['existing']} here already; {$preview['unpublished']} not shown on the platform.\n");
            $this->unmatched($preview['unmatched']);

            return ExitCode::OK;
        }

        $report = $imports->import($result->reviews, $options);
        $this->stdout("Added {$report->added}; {$report->unchanged} here already; {$report->failed} failed.\n", Console::FG_GREEN);
        $this->unmatched(array_values(array_unique($report->unmatched)));

        return $report->failed > 0 ? ExitCode::SOFTWARE : ExitCode::OK;
    }

    /**
     * @param  array<int, string>  $unmatched
     */
    protected function unmatched(array $unmatched): void
    {
        if ($unmatched === []) {
            return;
        }

        $this->stdout(count($unmatched)." matched nothing on this site:\n", Console::FG_YELLOW);

        foreach (array_slice($unmatched, 0, 30) as $item) {
            $this->stdout("  {$item}\n");
        }
    }
}
