<?php

namespace nineteenninetyfour\reviews\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use nineteenninetyfour\reviews\models\ImportOptions;
use nineteenninetyfour\reviews\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Importing reviews from a file another platform exported: upload it, see
 * what would be added, then add it.
 */
class ImportController extends Controller
{
    /** The largest file the control panel takes; bigger ones go through `craft reviews/import`. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('reviews/import/index', [
            'formats' => Plugin::getInstance()->imports->formats(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'options' => new ImportOptions,
        ]);
    }

    /**
     * Upload and look: what the file is, and what would be added.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $file = UploadedFile::getInstanceByName('file');

        if (! $file || $file->getHasError()) {
            Craft::$app->getSession()->setError(Craft::t('reviews', 'Choose a CSV or JSON file.'));

            return $this->redirect('reviews/import');
        }

        if ($file->size > self::MAX_BYTES) {
            Craft::$app->getSession()->setError(Craft::t('reviews', 'That file’s too big to import here: use the console command, php craft reviews/import, instead.'));

            return $this->redirect('reviews/import');
        }

        $token = StringHelper::randomString(24);
        $path = $this->path($token);
        FileHelper::createDirectory(dirname($path));
        $file->saveAs($path);

        return $this->showPreview($token);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $token = (string) $this->request->getRequiredBodyParam('token');
        $path = $this->path($token);

        if (! is_file($path)) {
            throw new BadRequestHttpException('That import has expired. Upload the file again.');
        }

        $result = Plugin::getInstance()->imports->read((string) file_get_contents($path), $this->format());
        $options = $this->options();

        @set_time_limit(0);
        $report = Plugin::getInstance()->imports->import($result->reviews, $options);
        FileHelper::unlink($path);

        return $this->renderTemplate('reviews/import/done', [
            'report' => $report,
            'skipped' => $result->skipped,
            'format' => $result->format?->label(),
        ]);
    }

    protected function showPreview(string $token): Response
    {
        $imports = Plugin::getInstance()->imports;
        $result = $imports->read((string) file_get_contents($this->path($token)), $this->format());
        $options = $this->options();

        return $this->renderTemplate('reviews/import/preview', [
            'token' => $token,
            'result' => $result,
            'preview' => $result->format ? $imports->preview($result->reviews, $options) : null,
            'options' => $options,
            'formatKey' => $this->format(),
            'business' => $options->businessElementId ? Entry::find()->id($options->businessElementId)->status(null)->one() : null,
        ]);
    }

    protected function options(): ImportOptions
    {
        $business = $this->request->getBodyParam('businessElementId');
        $business = is_array($business) ? ($business[0] ?? null) : $business;

        return new ImportOptions([
            'counted' => (bool) $this->request->getBodyParam('counted', false),
            'status' => $this->request->getBodyParam('status') === 'pending' ? 'pending' : 'keep',
            'includeUnpublished' => (bool) $this->request->getBodyParam('includeUnpublished', false),
            'businessElementId' => is_numeric($business) ? (int) $business : null,
            'siteId' => is_numeric($this->request->getBodyParam('siteId')) ? (int) $this->request->getBodyParam('siteId') : null,
        ]);
    }

    protected function format(): ?string
    {
        $format = $this->request->getBodyParam('format');

        return is_string($format) && array_key_exists($format, Plugin::getInstance()->imports->formats()) ? $format : null;
    }

    protected function path(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9]{24}$/', $token)) {
            throw new BadRequestHttpException('Not a valid import.');
        }

        return Craft::$app->getPath()->getTempPath().'/reviews-import/'.$token.'.txt';
    }
}
