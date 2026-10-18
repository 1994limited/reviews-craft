<?php

namespace nineteenninetyfour\reviews\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;

/**
 * Approve, reject or mark reviews as spam, several at once.
 */
class SetReviewStatus extends ElementAction
{
    public string $status = Review::STATUS_APPROVED;

    public function getTriggerLabel(): string
    {
        return match ($this->status) {
            Review::STATUS_REJECTED => Craft::t('reviews', 'Reject'),
            Review::STATUS_SPAM => Craft::t('reviews', 'Mark as spam'),
            default => Craft::t('reviews', 'Approve'),
        };
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if (! Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MODERATE)) {
            $this->setMessage(Craft::t('reviews', 'You can’t moderate reviews.'));

            return false;
        }

        $reviews = Plugin::getInstance()->reviews;
        $failed = 0;

        /** @var Review $review */
        foreach ($query->status(null)->all() as $review) {
            $ok = match ($this->status) {
                Review::STATUS_REJECTED => $reviews->reject($review),
                Review::STATUS_SPAM => $reviews->spam($review),
                default => $reviews->approve($review),
            };

            $failed += $ok ? 0 : 1;
        }

        $this->setMessage($failed === 0
            ? Craft::t('reviews', 'Reviews updated.')
            : Craft::t('reviews', '{count, plural, =1{One review couldn’t be updated.} other{# reviews couldn’t be updated.}}', ['count' => $failed]));

        return $failed === 0;
    }
}
