<?php

namespace nineteenninetyfour\reviews\integrations;

use Craft;
use nineteenninetyfour\reviews\Plugin;
use nystudio107\seomatic\helpers\DynamicMeta;
use Throwable;
use yii\base\Event;

/**
 * Adds the rating and reviews to SEOmatic's own JSON-LD for the page (its
 * "mainEntityOfPage"), so search engines see one Product, not two. Its
 * schema type has to be one that takes reviews: Product (or a kind of
 * product), Book, Course, LocalBusiness, Recipe and so on. A WebPage
 * doesn't, so Reviews leaves it alone and logs why.
 */
class Seomatic
{
    public static function installed(): bool
    {
        return class_exists(\nystudio107\seomatic\Seomatic::class) && Craft::$app->getPlugins()->isPluginEnabled('seomatic');
    }

    public static function register(): void
    {
        if (! class_exists(DynamicMeta::class)) {
            return;
        }

        Event::on(DynamicMeta::class, DynamicMeta::EVENT_ADD_DYNAMIC_META, function () {
            try {
                self::apply();
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t add to SEOmatic’s JSON-LD: '.$e->getMessage(), 'reviews');
            }
        });
    }

    public static function apply(): void
    {
        $plugin = Plugin::getInstance();
        $element = \nystudio107\seomatic\Seomatic::$matchedElement ?? null;

        if ($plugin->getSettings()->schemaOutput !== 'seomatic' || ! $element || ! $plugin->reviews->reviewable($element)) {
            return;
        }

        $parts = $plugin->reviews->schemaParts($element);
        $jsonLd = \nystudio107\seomatic\Seomatic::$plugin->jsonLd->get('mainEntityOfPage');

        if ($parts === null || $jsonLd === null) {
            return;
        }

        if (! property_exists($jsonLd, 'aggregateRating')) {
            Craft::info(sprintf('Reviews didn’t add its rating to SEOmatic: the page’s schema type (%s) doesn’t take reviews. Choose Product, Book or similar in SEOmatic.', $jsonLd->type ?? get_class($jsonLd)), 'reviews');

            return;
        }

        $jsonLd->aggregateRating = $parts['aggregateRating'];

        if (isset($parts['review']) && property_exists($jsonLd, 'review')) {
            $jsonLd->review = $parts['review'];
        }
    }
}
