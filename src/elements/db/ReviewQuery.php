<?php

namespace nineteenninetyfour\reviews\elements\db;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\migrations\Install;

/**
 * Finds reviews. Only approved ones unless asked otherwise, so a template's
 * `craft.reviews.reviews()` never shows one an editor hasn't seen.
 *
 * @template TKey of array-key
 * @template TElement of Review
 *
 * @extends ElementQuery<TKey,TElement>
 */
class ReviewQuery extends ElementQuery
{
    public array|string|null $status = [Review::STATUS_APPROVED];

    public mixed $elementId = null;

    public mixed $reviewSiteId = null;

    public mixed $rating = null;

    public ?bool $verified = null;

    public ?bool $withPhotos = null;

    public ?bool $counted = null;

    public mixed $userId = null;

    public mixed $email = null;

    public mixed $source = null;

    public mixed $sourceId = null;

    public mixed $fingerprint = null;

    protected array $defaultOrderBy = ['elements.dateCreated' => SORT_DESC, 'elements.id' => SORT_DESC];

    /**
     * The element (or its ID, or IDs) reviewed.
     */
    public function element(mixed $value): static
    {
        $this->elementId = $value instanceof ElementInterface ? $value->id : $value;

        return $this;
    }

    public function elementId(mixed $value): static
    {
        $this->elementId = $value;

        return $this;
    }

    public function reviewSiteId(mixed $value): static
    {
        $this->reviewSiteId = $value;

        return $this;
    }

    /**
     * A rating, ratings ([4, 5]) or a comparison ('>= 4').
     */
    public function rating(mixed $value): static
    {
        $this->rating = $value;

        return $this;
    }

    public function verified(?bool $value = true): static
    {
        $this->verified = $value;

        return $this;
    }

    public function withPhotos(?bool $value = true): static
    {
        $this->withPhotos = $value;

        return $this;
    }

    /**
     * Only reviews that count towards the average (or only those that don't).
     */
    public function counted(?bool $value = true): static
    {
        $this->counted = $value;

        return $this;
    }

    public function userId(mixed $value): static
    {
        $this->userId = $value;

        return $this;
    }

    public function email(mixed $value): static
    {
        $this->email = is_string($value) ? mb_strtolower($value) : $value;

        return $this;
    }

    /**
     * Where reviews came from: ':empty:' for this site's own, a platform's key.
     */
    public function source(mixed $value): static
    {
        $this->source = $value;

        return $this;
    }

    public function sourceId(mixed $value): static
    {
        $this->sourceId = $value;

        return $this;
    }

    public function fingerprint(mixed $value): static
    {
        $this->fingerprint = $value;

        return $this;
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Review::STATUS_PENDING, Review::STATUS_APPROVED, Review::STATUS_REJECTED, Review::STATUS_SPAM => ['reviews_reviews.reviewStatus' => $status],
            default => parent::statusCondition($status),
        };
    }

    protected function beforePrepare(): bool
    {
        if (! parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable(Install::REVIEWS);

        $this->query->addSelect([
            'reviews_reviews.elementId',
            'reviews_reviews.reviewSiteId',
            'reviews_reviews.rating',
            'reviews_reviews.ratings',
            'reviews_reviews.body',
            'reviews_reviews.name',
            'reviews_reviews.email',
            'reviews_reviews.userId',
            'reviews_reviews.reviewStatus',
            'reviews_reviews.verified',
            'reviews_reviews.orderId',
            'reviews_reviews.reply',
            'reviews_reviews.replyById',
            'reviews_reviews.dateReplied',
            'reviews_reviews.note',
            'reviews_reviews.photos',
            'reviews_reviews.helpful',
            'reviews_reviews.dateApproved',
            'reviews_reviews.counted',
            'reviews_reviews.source',
            'reviews_reviews.sourceId',
            'reviews_reviews.sourceUrl',
            'reviews_reviews.fingerprint',
        ]);

        foreach (['elementId', 'reviewSiteId', 'rating', 'userId', 'email', 'source', 'sourceId', 'fingerprint'] as $param) {
            if ($this->$param !== null) {
                $this->subQuery->andWhere(Db::parseParam('reviews_reviews.'.$param, $this->$param));
            }
        }

        if ($this->verified !== null) {
            $this->subQuery->andWhere(['reviews_reviews.verified' => $this->verified]);
        }

        if ($this->counted !== null) {
            $this->subQuery->andWhere(['reviews_reviews.counted' => $this->counted]);
        }

        if ($this->withPhotos === true) {
            $this->subQuery->andWhere(['not', ['reviews_reviews.photos' => null]]);
        } elseif ($this->withPhotos === false) {
            $this->subQuery->andWhere(['reviews_reviews.photos' => null]);
        }

        return true;
    }
}
