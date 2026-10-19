<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use DateTime;
use NineteenNinetyFour\Reviews\Core\Rating\Summary;
use NineteenNinetyFour\Reviews\Core\Schema\SchemaBuilder;
use NineteenNinetyFour\Reviews\Core\Schema\SchemaReview;
use NineteenNinetyFour\Reviews\Core\Schema\Subject;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\events\ReviewEvent;
use nineteenninetyfour\reviews\fields\ReviewsField;
use nineteenninetyfour\reviews\integrations\Blitz;
use nineteenninetyfour\reviews\integrations\Commerce;
use nineteenninetyfour\reviews\integrations\Seomatic;
use nineteenninetyfour\reviews\jobs\RefreshAfterVotes;
use nineteenninetyfour\reviews\migrations\Install;
use nineteenninetyfour\reviews\Plugin;
use Throwable;
use yii\caching\TagDependency;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * Reviews, from what can be reviewed to moderating them. Every change goes
 * through here, so each element's numbers and its cached pages stay up to
 * date.
 */
class Reviews extends Component
{
    /** How long "helpful" votes wait to refresh the page they're on, together. */
    public const VOTE_REFRESH_SECONDS = 300;

    public const EVENT_AFTER_SUBMIT = 'afterSubmit';

    public const EVENT_AFTER_APPROVE = 'afterApprove';

    public const EVENT_AFTER_REJECT = 'afterReject';

    public const EVENT_AFTER_REPLY = 'afterReply';

    // What can be reviewed.

    /**
     * Whether visitors may review an element: a live entry in a section
     * that takes reviews, or a live product of a type that does, with a
     * page on the site, and reviews not switched off with a Reviews field.
     */
    public function reviewable(mixed $element): bool
    {
        if (! $element instanceof ElementInterface || ! $element->id || $element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($element instanceof Entry) {
            $handle = $element->getSection()?->handle;

            if ($handle === null || ! in_array($handle, $settings->sections, true)) {
                return false;
            }
        } elseif (Commerce::isProduct($element)) {
            if (! in_array(Commerce::productTypeHandle($element), $settings->productTypes, true)) {
                return false;
            }
        } else {
            return false;
        }

        if ($element->getStatus() !== Entry::STATUS_LIVE && $element->getStatus() !== 'enabled') {
            return false;
        }

        if ($element->getUrl() === null) {
            return false;
        }

        return ReviewsField::allows($element);
    }

    /**
     * The criteria reviews are rated on besides the overall rating.
     *
     * @return array<int, array{handle: string, label: string}>
     */
    public function criteria(): array
    {
        $criteria = [];

        foreach (Plugin::getInstance()->getSettings()->criteria as $criterion) {
            $handle = is_array($criterion) && is_string($criterion['handle'] ?? null) ? trim($criterion['handle']) : '';
            $label = is_array($criterion) && is_string($criterion['label'] ?? null) ? trim($criterion['label']) : '';

            if (! preg_match('/^[a-z][a-zA-Z0-9_]{0,40}$/', $handle) || isset($criteria[$handle])) {
                continue;
            }

            $criteria[$handle] = ['handle' => $handle, 'label' => $label !== '' ? Craft::t('site', $label) : ucfirst(str_replace('_', ' ', $handle))];
        }

        return array_values($criteria);
    }

    /**
     * The site reviews are kept apart by: the current one when each site has
     * its own reviews, or null when they're shared.
     */
    public function siteScope(?int $siteId = null): ?int
    {
        if (Plugin::getInstance()->getSettings()->sites !== 'separate') {
            return null;
        }

        return $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
    }

    // Numbers.

    /**
     * An element's approved reviews in numbers, counting only those that
     * count (this site's own, and imports an editor said to count), and
     * with separate sites, only the current site's. Cached until one of
     * its reviews changes.
     */
    public function summary(ElementInterface|int $element, ?int $siteId = null): Summary
    {
        // With separate sites, the element's own site's, unless one's given.
        $siteId = $this->siteScope($siteId ?? ($element instanceof ElementInterface ? $element->siteId : null));
        $id = $element instanceof ElementInterface ? (int) $element->id : $element;
        $key = 'reviews:summary:'.$id.':'.($siteId ?? '*');

        $tally = Craft::$app->getCache()->getOrSet($key, fn () => $this->tally($id, $siteId), null, new TagDependency(['tags' => [self::summaryTag($id)]]));

        return Summary::from($tally);
    }

    /**
     * @return array{stars: array<int, int>, criteria: array<string, array{sum: int, count: int}>}
     */
    protected function tally(int $elementId, ?int $siteId): array
    {
        $base = (new Query)
            ->from(['r' => Install::REVIEWS])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]]')
            ->where(['r.elementId' => $elementId, 'r.reviewStatus' => ReviewStatus::Approved->value, 'r.counted' => true, 'e.dateDeleted' => null])
            ->andFilterWhere(['r.reviewSiteId' => $siteId]);

        $stars = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        foreach ((clone $base)->select(['r.rating', 'total' => 'COUNT(*)'])->groupBy(['r.rating'])->all() as $row) {
            if (isset($stars[(int) $row['rating']])) {
                $stars[(int) $row['rating']] = (int) $row['total'];
            }
        }

        $criteria = [];

        foreach ((clone $base)->select(['r.ratings'])->andWhere(['not', ['r.ratings' => null]])->column() as $json) {
            foreach ((array) json_decode((string) $json, true) as $handle => $score) {
                if (is_string($handle) && is_numeric($score) && $score >= 1 && $score <= 5) {
                    $criteria[$handle]['sum'] = ($criteria[$handle]['sum'] ?? 0) + (int) $score;
                    $criteria[$handle]['count'] = ($criteria[$handle]['count'] ?? 0) + 1;
                }
            }
        }

        return ['stars' => $stars, 'criteria' => $criteria];
    }

    public static function summaryTag(int $elementId): string
    {
        return 'reviews:summary:'.$elementId;
    }

    // Schema.

    /** How many reviews go in the schema, newest first. */
    public const SCHEMA_REVIEWS = 10;

    /**
     * The rating and latest reviews for schema, or null with none. Only
     * reviews that count: never ones gathered from other websites, which
     * Google's guidelines say mustn't be marked up.
     *
     * @return array{aggregateRating: array<string, mixed>, review?: array<int, array<string, mixed>>}|null
     */
    public function schemaParts(ElementInterface $element): ?array
    {
        return SchemaBuilder::parts($this->summary($element), $this->schemaReviews($element));
    }

    /**
     * The whole JSON-LD for an element: its type, name and address, with
     * the rating and reviews.
     *
     * @return array<string, mixed>|null
     */
    public function schema(ElementInterface $element, ?string $type = null): ?array
    {
        $url = $element->getUrl();

        $subject = new Subject(
            name: (string) $element->title,
            type: $type ?? (Commerce::isProduct($element) ? 'Product' : Plugin::getInstance()->getSettings()->schemaType),
            url: $url,
            id: $url !== null ? $url.'#reviews' : null,
        );

        return SchemaBuilder::build($subject, $this->summary($element), $this->schemaReviews($element));
    }

    /**
     * @return array<int, SchemaReview>
     */
    protected function schemaReviews(ElementInterface $element): array
    {
        $reviews = [];

        foreach (Review::find()->elementId($element->id)->reviewSiteId($this->siteScope($element->siteId))->counted()->limit(self::SCHEMA_REVIEWS)->all() as $review) {
            $reviews[] = new SchemaReview(
                rating: $review->rating,
                author: $review->getDisplayName(),
                date: $review->dateCreated?->format('Y-m-d'),
                title: $review->title,
                body: $review->body,
            );
        }

        return $reviews;
    }

    // People.

    /**
     * The review someone already wrote of an element, if any: by their
     * account when signed in, or else their email. With separate sites,
     * only on the current site.
     */
    public function existing(int $elementId, ?User $user, ?string $email = null, ?int $siteId = null): ?Review
    {
        $query = Review::find()->elementId($elementId)->reviewSiteId($this->siteScope($siteId))->status(null)->source(':empty:');

        if ($user) {
            $query->userId($user->id);
        } elseif ($email) {
            $query->email(mb_strtolower($email));
        } else {
            return null;
        }

        return $query->one();
    }

    // Saving and moderating.

    /**
     * Save a review, without the validation an editor's change would skip.
     */
    public function save(Review $review, bool $validate = true): bool
    {
        $previous = $review->id ? (new Query)->from(Install::REVIEWS)->select('elementId')->where(['id' => $review->id])->scalar() : null;

        if (! Craft::$app->getElements()->saveElement($review, $validate)) {
            return false;
        }

        $this->changed((int) $review->elementId);

        if ($previous && (int) $previous !== (int) $review->elementId) {
            $this->changed((int) $previous);
        }

        return true;
    }

    public function approve(Review $review): bool
    {
        if ($review->isApproved()) {
            return true;
        }

        return $this->moderate($review, ReviewStatus::Approved, self::EVENT_AFTER_APPROVE);
    }

    public function reject(Review $review, ?string $note = null, ReviewStatus $status = ReviewStatus::Rejected): bool
    {
        if ($note !== null) {
            $review->note = trim($note) === '' ? null : trim($note);
        }

        return $this->moderate($review, $status === ReviewStatus::Spam ? ReviewStatus::Spam : ReviewStatus::Rejected, self::EVENT_AFTER_REJECT);
    }

    public function spam(Review $review): bool
    {
        return $this->reject($review, null, ReviewStatus::Spam);
    }

    /**
     * Back to waiting for an editor, as when a reviewer changes their review.
     */
    public function hold(Review $review): bool
    {
        return $this->moderate($review, ReviewStatus::Pending, null);
    }

    /**
     * Change a review's status, moving its photos on to the site (approved)
     * or off it (anything else). The old copies are only removed once it's
     * saved, and the new ones if it can't be.
     */
    protected function moderate(Review $review, ReviewStatus $status, ?string $event): bool
    {
        $photos = Plugin::getInstance()->photos;
        $before = ['reviewStatus' => $review->reviewStatus, 'dateApproved' => $review->dateApproved, 'photos' => $review->photos, 'note' => $review->note];

        $done = $status === ReviewStatus::Approved ? $photos->publish($review) : $photos->unpublish($review);

        $review->reviewStatus = $status;
        $review->dateApproved = $status === ReviewStatus::Approved ? new DateTime : null;

        try {
            $saved = $this->save($review, false);
        } catch (Throwable $e) {
            $saved = false;
            Craft::error('Reviews couldn’t save review '.$review->id.': '.$e->getMessage(), 'reviews');
        }

        if (! $saved) {
            $photos->undo($done);

            foreach ($before as $property => $value) {
                $review->$property = $value;
            }

            return false;
        }

        $photos->finish($done);

        if ($event && $this->hasEventHandlers($event)) {
            $this->trigger($event, new ReviewEvent(['review' => $review]));
        }

        return true;
    }

    /**
     * Set (or, when empty, remove) the site's public reply.
     */
    public function reply(Review $review, ?string $reply, ?User $by = null): bool
    {
        $reply = $reply !== null ? trim($reply) : null;
        $reply = $reply === '' ? null : $reply;
        $replied = $reply !== null && $reply !== $review->reply;

        $review->reply = $reply;
        $review->replyById = $reply !== null ? ($by->id ?? $review->replyById) : null;
        $review->dateReplied = $reply !== null ? ($replied ? new DateTime : $review->dateReplied) : null;

        if (! $this->save($review, false)) {
            return false;
        }

        if ($replied && $this->hasEventHandlers(self::EVENT_AFTER_REPLY)) {
            $this->trigger(self::EVENT_AFTER_REPLY, new ReviewEvent(['review' => $review]));
        }

        return true;
    }

    public function delete(Review $review, bool $hard = true): bool
    {
        if (! Craft::$app->getElements()->deleteElement($review, $hard)) {
            return false;
        }

        $this->changed((int) $review->elementId);

        return true;
    }

    /**
     * Record a "helpful" vote. False when the voter has voted already.
     */
    public function vote(Review $review, string $voter): bool
    {
        if (! $review->isApproved()) {
            return false;
        }

        $db = Craft::$app->getDb();

        try {
            $db->transaction(function () use ($db, $review, $voter) {
                $db->createCommand()->insert(Install::VOTES, ['reviewId' => $review->id, 'voter' => $voter, 'dateCreated' => (new DateTime)->format('Y-m-d H:i:s')])->execute();
                $db->createCommand()->update(Install::REVIEWS, ['helpful' => new Expression('[[helpful]] + 1')], ['id' => $review->id], [], false)->execute();
            });
        } catch (IntegrityException) {
            return false;
        }

        $review->helpful = (int) (new Query)->from(Install::REVIEWS)->select('helpful')->where(['id' => $review->id])->scalar();

        // A vote changes only the count beside the button, so the page's
        // caches are refreshed once for every vote in the next few minutes,
        // not for each.
        $key = 'reviews:vote-refresh:'.$review->elementId;

        if (Craft::$app->getCache()->add($key, true, self::VOTE_REFRESH_SECONDS)) {
            try {
                Craft::$app->getQueue()->delay(self::VOTE_REFRESH_SECONDS)->push(new RefreshAfterVotes(['elementId' => (int) $review->elementId]));
            } catch (Throwable $e) {
                // Not queued: let the next vote try, and refresh now.
                Craft::$app->getCache()->delete($key);
                $this->changed((int) $review->elementId, summary: false);
            }
        }

        return true;
    }

    /**
     * Delete an element's reviews for good, as elements, so their photos go
     * too: when the element itself is about to be deleted for good.
     */
    public function deleteFor(int $elementId): int
    {
        $deleted = 0;

        foreach (Review::find()->elementId($elementId)->status(null)->trashed(null)->all() as $review) {
            $deleted += $this->deleteForGood($review) ? 1 : 0;
        }

        TagDependency::invalidate(Craft::$app->getCache(), self::summaryTag($elementId));

        return $deleted;
    }

    /**
     * Reviews whose element was deleted some other way (by Craft's garbage
     * collection, which doesn't fire element events): deleted the same way.
     */
    public function deleteOrphans(): int
    {
        $deleted = 0;

        foreach (Review::find()->elementId(':empty:')->status(null)->trashed(null)->all() as $review) {
            $deleted += $this->deleteForGood($review) ? 1 : 0;
        }

        // Elements whose review row is gone altogether (from before 1.0.1).
        Craft::$app->getGc()->deletePartialElements(Review::class, Install::REVIEWS, 'id');

        return $deleted;
    }

    protected function deleteForGood(Review $review): bool
    {
        try {
            if (Craft::$app->getElements()->deleteElement($review, true)) {
                return true;
            }
        } catch (Throwable $e) {
            Craft::warning('Reviews couldn’t delete review '.$review->id.': '.$e->getMessage(), 'reviews');

            return false;
        }

        Craft::warning('Reviews couldn’t delete review '.$review->id.'.', 'reviews');

        return false;
    }

    /**
     * Take a person's name and email off their reviews, keeping what they
     * wrote, or delete the reviews. Returns how many.
     */
    public function forget(?string $email, ?int $userId = null, bool $delete = false): int
    {
        $reviews = [];

        if ($email) {
            foreach (Review::find()->status(null)->email(mb_strtolower($email))->all() as $review) {
                $reviews[$review->id] = $review;
            }
        }

        if ($userId) {
            foreach (Review::find()->status(null)->userId($userId)->all() as $review) {
                $reviews[$review->id] = $review;
            }
        }

        foreach ($reviews as $review) {
            if ($delete) {
                $this->delete($review);

                continue;
            }

            // Left empty: "Anonymous" is shown in each visitor's language.
            $review->name = '';
            $review->email = null;
            $review->userId = null;
            $this->save($review, false);
        }

        return count($reviews);
    }

    /**
     * An element's reviews changed: its numbers and its cached pages are out
     * of date.
     */
    public function changed(int $elementId, bool $summary = true): void
    {
        if ($summary) {
            TagDependency::invalidate(Craft::$app->getCache(), self::summaryTag($elementId));
        }

        $element = Craft::$app->getElements()->getElementById($elementId);

        if (! $element) {
            return;
        }

        // Each on its own: one failing doesn't stop the others.
        foreach ([
            fn () => Craft::$app->getElements()->invalidateCachesForElement($element),
            fn () => Seomatic::refresh($element),
            fn () => Blitz::refresh($element),
        ] as $clear) {
            try {
                $clear();
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t clear caches for element '.$elementId.': '.$e->getMessage(), 'reviews');
            }
        }
    }
}
