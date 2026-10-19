<?php

namespace nineteenninetyfour\reviews\integrations;

use Craft;
use craft\base\ElementInterface;

/**
 * Blitz caches whole pages. When an element's reviews change, its cached
 * pages are refreshed, as they would be if the element itself were saved.
 */
class Blitz
{
    public static function installed(): bool
    {
        return class_exists(\putyourlightson\blitz\Blitz::class) && Craft::$app->getPlugins()->isPluginEnabled('blitz');
    }

    public static function refresh(ElementInterface $element): void
    {
        if (! class_exists(\putyourlightson\blitz\Blitz::class) || ! self::installed()) {
            return;
        }

        $refresh = \putyourlightson\blitz\Blitz::$plugin->refreshCache ?? null;

        if ($refresh && method_exists($refresh, 'addElement')) {
            $refresh->addElement($element);
        }
    }
}
