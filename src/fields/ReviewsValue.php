<?php

namespace nineteenninetyfour\reviews\fields;

use craft\base\ElementInterface;
use NineteenNinetyFour\Reviews\Core\Rating\Summary;
use nineteenninetyfour\reviews\elements\db\ReviewQuery;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;

/**
 * A Reviews field's value: whether the element takes reviews, and its
 * numbers. `{{ entry.reviews.average }}`, `{{ entry.reviews.count }}`.
 */
class ReviewsValue
{
    public function __construct(public bool $enabled = true, public ?ElementInterface $element = null) {}

    public function getSummary(): Summary
    {
        return $this->element?->id ? Plugin::getInstance()->reviews->summary($this->element) : new Summary;
    }

    public function getAverage(): ?float
    {
        return $this->getSummary()->average();
    }

    public function getCount(): int
    {
        return $this->getSummary()->count();
    }

    /**
     * @return ReviewQuery<int, Review>
     */
    public function getReviews(): ReviewQuery
    {
        return Review::find()->elementId($this->element->id ?? 0)->reviewSiteId(Plugin::getInstance()->reviews->siteScope());
    }

    public function __toString(): string
    {
        return (string) ($this->getAverage() ?? '');
    }
}
