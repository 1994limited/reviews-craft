<?php

namespace nineteenninetyfour\reviews\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;

/**
 * The reviews waiting for an editor.
 */
class PendingReviews extends Widget
{
    public int $limit = 5;

    public static function displayName(): string
    {
        return Craft::t('reviews', 'Reviews waiting');
    }

    public static function icon(): ?string
    {
        return 'star';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MODERATE);
    }

    public function getBodyHtml(): ?string
    {
        if (! Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MODERATE)) {
            return null;
        }

        $query = Review::find()->status(Review::STATUS_PENDING)->orderBy(['elements.dateCreated' => SORT_ASC]);
        $total = (int) (clone $query)->count();

        if ($total === 0) {
            return Html::tag('p', Craft::t('reviews', 'Nothing waiting.'), ['class' => 'zilch small']);
        }

        $items = '';

        foreach ($query->limit(max(1, $this->limit))->all() as $review) {
            $items .= Html::tag('li', Html::a(
                Html::tag('span', str_repeat('★', $review->rating), ['style' => 'color: #e5a00d;']).' '.Html::encode($review->getUiLabel()),
                $review->getCpEditUrl()
            ).Html::tag('div', Html::encode(trim(($review->getElement()->title ?? '').' · '.$review->name, ' ·')), ['class' => 'light smalltext']), ['style' => 'margin-bottom: 8px;']);
        }

        return Html::tag('ul', $items).Html::a(Craft::t('reviews', 'All {count} waiting', ['count' => $total]), UrlHelper::cpUrl('reviews', ['source' => 'pending']), ['class' => 'go']);
    }
}
