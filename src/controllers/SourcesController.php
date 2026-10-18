<?php

namespace nineteenninetyfour\reviews\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use NineteenNinetyFour\Reviews\Core\Sync\Sources;
use nineteenninetyfour\reviews\models\Source;
use nineteenninetyfour\reviews\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The platforms reviews are synced from.
 */
class SourcesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('reviews/sources/index', [
            'sources' => Plugin::getInstance()->sync->all(),
            'platforms' => Sources::all(),
        ]);
    }

    public function actionEdit(?int $sourceId = null, ?Source $source = null): Response
    {
        if ($source === null) {
            $source = $sourceId ? Plugin::getInstance()->sync->find($sourceId) : new Source(['type' => (string) $this->request->getQueryParam('type', 'feefo')]);
        }

        if (! $source) {
            throw new NotFoundHttpException;
        }

        return $this->renderTemplate('reviews/sources/edit', [
            'source' => $source,
            'platform' => $source->platform(),
            'platforms' => Sources::all(),
            'business' => $source->businessElementId ? Entry::find()->id($source->businessElementId)->status(null)->one() : null,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $sync = Plugin::getInstance()->sync;
        $id = $this->request->getBodyParam('sourceId');
        $source = $id ? $sync->find((int) $id) : new Source;

        if (! $source) {
            throw new NotFoundHttpException;
        }

        $business = $this->request->getBodyParam('businessElementId');
        $business = is_array($business) ? ($business[0] ?? null) : $business;

        $source->type = $source->id ? $source->type : (string) $this->request->getBodyParam('type');
        $source->name = trim((string) $this->request->getBodyParam('name'));
        $source->enabled = (bool) $this->request->getBodyParam('enabled');
        $source->countInSummary = (bool) $this->request->getBodyParam('countInSummary');
        $source->frequency = max(1, (int) $this->request->getBodyParam('frequency', 24));
        $source->businessElementId = is_numeric($business) ? (int) $business : null;

        $settings = [];
        $posted = (array) $this->request->getBodyParam('settings', []);

        foreach ($source->platform()?->fields() ?? [] as $key => $field) {
            $value = trim((string) ($posted[$key] ?? ''));

            // A secret left blank keeps the one saved.
            if ($field['secret'] && $value === '' && isset($source->settings[$key])) {
                $value = $source->settings[$key];
            }

            $settings[$key] = $value;
        }

        $source->settings = $settings;

        if (! $sync->save($source)) {
            Craft::$app->getUrlManager()->setRouteParams(['source' => $source]);

            return $this->asModelFailure($source, Craft::t('reviews', 'Couldn’t save the source.'), 'source');
        }

        return $this->asModelSuccess($source, Craft::t('reviews', 'Source saved.'), 'source', [], 'reviews/sources');
    }

    public function actionSyncNow(): Response
    {
        $this->requirePostRequest();
        $source = Plugin::getInstance()->sync->find((int) $this->request->getRequiredBodyParam('sourceId'));

        if (! $source) {
            throw new NotFoundHttpException;
        }

        @set_time_limit(0);

        try {
            $report = Plugin::getInstance()->sync->run($source, (bool) $this->request->getBodyParam('all'));
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('reviews', 'Synced: {added} added, {updated} updated, {unmatched} matched nothing.', [
            'added' => $report->added,
            'updated' => $report->updated,
            'unmatched' => count($report->unmatched),
        ]));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $source = Plugin::getInstance()->sync->find((int) $this->request->getRequiredBodyParam('sourceId'));

        if ($source) {
            Plugin::getInstance()->sync->delete($source, (bool) $this->request->getBodyParam('withReviews'));
        }

        return $this->asSuccess(Craft::t('reviews', 'Source removed.'), [], 'reviews/sources');
    }
}
