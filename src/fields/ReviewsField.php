<?php

namespace nineteenninetyfour\reviews\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use yii\db\Schema;

/**
 * Switches reviews off for one entry or product, in a section that takes
 * them, and shows its rating in the editor and element indexes. Optional:
 * without one, every entry in a reviewed section takes reviews.
 */
class ReviewsField extends Field
{
    public static function displayName(): string
    {
        return Craft::t('reviews', 'Reviews');
    }

    public static function icon(): string
    {
        return 'star';
    }

    public static function phpType(): string
    {
        return ReviewsValue::class;
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    /**
     * Whether an element's Reviews field (if it has one) lets it take reviews.
     */
    public static function allows(ElementInterface $element): bool
    {
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof self) {
                $value = $element->getFieldValue($field->handle);

                return ! $value instanceof ReviewsValue || $value->enabled;
            }
        }

        return true;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof ReviewsValue) {
            $value->element = $element;

            return $value;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        $enabled = is_array($value) ? (bool) ($value['enabled'] ?? true) : ($value === null || $value === '' ? true : (bool) $value);

        return new ReviewsValue($enabled, $element);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return ['enabled' => $value instanceof ReviewsValue ? $value->enabled : true];
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        /** @var ReviewsValue $value */
        $html = Cp::lightswitchHtml([
            'name' => $this->handle.'[enabled]',
            'on' => $value->enabled,
            'label' => Craft::t('reviews', 'Takes reviews'),
        ]);

        if ($element?->id) {
            $count = $value->getCount();

            $html .= Html::tag('p', $count
                ? Craft::t('reviews', '{average} out of 5, from {count, plural, =1{1 review} other{# reviews}}.', ['average' => $value->getSummary()->rounded(), 'count' => $count])
                : Craft::t('reviews', 'No reviews yet.'), ['class' => 'light', 'style' => 'margin-top: 8px;']);

            $html .= Html::a(Craft::t('reviews', 'Moderate reviews'), UrlHelper::cpUrl('reviews'), ['class' => 'go']);
        }

        return $html;
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (! $value instanceof ReviewsValue || ! $value->getCount()) {
            return '';
        }

        return Html::encode(Craft::t('reviews', '{average} ★ ({count})', ['average' => $value->getSummary()->rounded(), 'count' => $value->getCount()]));
    }
}
