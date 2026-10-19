<?php

namespace nineteenninetyfour\reviews\controllers;

use Craft;
use craft\web\Controller;
use nineteenninetyfour\reviews\integrations\Commerce;
use nineteenninetyfour\reviews\integrations\Seomatic;
use nineteenninetyfour\reviews\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(true);

        return parent::beforeAction($action);
    }

    public function actionEdit(): Response
    {
        $plugin = Plugin::getInstance();
        $sections = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sections[] = ['label' => $section->name, 'value' => $section->handle];
        }

        $volumes = [['label' => Craft::t('reviews', 'Choose a volume'), 'value' => '']];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumes[] = ['label' => $volume->name, 'value' => $volume->handle];
        }

        $statuses = [];

        if (Commerce::installed()) {
            foreach (\craft\commerce\Plugin::getInstance()->getOrderStatuses()->getAllOrderStatuses() as $status) {
                $statuses[] = ['label' => $status->name, 'value' => $status->handle];
            }
        }

        return $this->renderTemplate('reviews/settings', [
            'settings' => $plugin->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('reviews')),
            'sections' => $sections,
            'productTypes' => array_map(fn ($handle, $name) => ['label' => $name, 'value' => $handle], array_keys(Commerce::productTypes()), Commerce::productTypes()),
            'commerce' => Commerce::installed(),
            'seomatic' => Seomatic::installed(),
            'volumes' => $volumes,
            'orderStatuses' => $statuses,
            'gd' => extension_loaded('gd'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $posted = (array) $this->request->getBodyParam('settings', []);

        foreach (['sections', 'productTypes', 'requestsOrderStatuses', 'excludedOrderStatuses'] as $list) {
            $posted[$list] = array_values(array_filter((array) ($posted[$list] ?? []), 'is_string'));
        }

        $posted['notify'] = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($posted['notify'] ?? '')) ?: [])));

        $criteria = [];

        foreach ((array) ($posted['criteria'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['handle'] ?? '')) !== '') {
                $criteria[] = ['handle' => trim((string) $row['handle']), 'label' => trim((string) ($row['label'] ?? ''))];
            }
        }

        $posted['criteria'] = $criteria;

        $settings = $plugin->getSettings();
        $settings->setAttributes($posted, false);

        if (! $settings->validate()) {
            return $this->asModelFailure($settings, Craft::t('reviews', 'Couldn’t save the settings.'), 'settings');
        }

        if (! Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return $this->asModelFailure($settings, Craft::t('reviews', 'Couldn’t save the settings.'), 'settings');
        }

        return $this->asSuccess(Craft::t('reviews', 'Settings saved.'));
    }
}
