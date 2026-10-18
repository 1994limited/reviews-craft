<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\web\Request;
use craft\web\UploadedFile;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\events\ReviewEvent;
use nineteenninetyfour\reviews\integrations\Commerce;
use nineteenninetyfour\reviews\models\ReviewForm;
use nineteenninetyfour\reviews\Plugin;

/**
 * Turning what a visitor sent from the review form into a review: who may
 * write one, checking what they wrote, and whether it's published straight
 * away or waits for an editor.
 */
class Submissions extends Component
{
    /** Session key of the orders opened from review request emails. */
    public const ORDERS = 'reviews.orders';

    /**
     * Why someone can't review an element, or null when they can:
     * "unavailable", "sign_in" or "buyers_only".
     *
     * @param  array<int, int>  $orders
     */
    public function refusal(mixed $element, ?User $user, array $orders = []): ?string
    {
        if (! Plugin::getInstance()->reviews->reviewable($element)) {
            return 'unavailable';
        }

        if (Plugin::getInstance()->getSettings()->who === 'users' && ! $user) {
            return 'sign_in';
        }

        if ($this->buyersOnly($element) && ! Commerce::purchase((int) $element->id, $user, $orders)) {
            return 'buyers_only';
        }

        return null;
    }

    public function refusalMessage(string $reason): string
    {
        return match ($reason) {
            'sign_in' => Craft::t('reviews', 'Sign in to write a review.'),
            'buyers_only' => Craft::t('reviews', 'Only customers who bought this can review it.'),
            default => Craft::t('reviews', 'This can’t be reviewed.'),
        };
    }

    public function buyersOnly(mixed $element): bool
    {
        return Plugin::getInstance()->getSettings()->buyersOnly && Commerce::isProduct($element);
    }

    /**
     * The orders the visitor opened review request emails for.
     *
     * @return array<int, int>
     */
    public static function sessionOrders(): array
    {
        // Only a visitor who has a session already: reading it mustn't
        // start one for everyone who sees a review form.
        $session = Craft::$app->has('session') ? Craft::$app->getSession() : null;

        if (! $session || (! $session->getIsActive() && ! $session->getHasSessionId())) {
            return [];
        }

        return array_values(array_map('intval', array_filter((array) $session->get(self::ORDERS, []), 'is_numeric')));
    }

    public static function rememberOrder(int $orderId): void
    {
        $session = Craft::$app->getSession();
        $orders = self::sessionOrders();

        if (! in_array($orderId, $orders, true)) {
            $orders[] = $orderId;
        }

        $session->set(self::ORDERS, array_slice($orders, -20));
    }

    /**
     * Save a review from the form. Returns the form, with errors to put
     * right, or the review. Throws a ReviewException when they can't review
     * at all, and DuplicateReview for a guest's second review.
     */
    public function submit(Request $request, ?User $user): ReviewForm|Review
    {
        $orders = self::sessionOrders();
        $id = $request->getBodyParam('elementId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $element = is_numeric($id) ? Craft::$app->getElements()->getElementById((int) $id, null, $siteId) : null;

        if ($reason = $this->refusal($element, $user, $orders)) {
            throw new ReviewException($this->refusalMessage($reason));
        }

        // What they sent is checked before anything about earlier reviews,
        // so the answer can't tell anyone whether an address has reviewed
        // the element before.
        $existing = $user ? Plugin::getInstance()->reviews->existing((int) $element->id, $user) : null;
        $form = $this->form($request, $user, $existing);

        if (! $form->validate()) {
            return $form;
        }

        $email = $user ? ($user->email ?: $form->email) : mb_strtolower((string) $form->email);
        $identity = $user ? 'user:'.$user->id : 'email:'.$email;
        $mutex = Craft::$app->getMutex();
        $lock = 'reviews:submit:'.sha1($element->id.'|'.$siteId.'|'.$identity);

        // One review per person per element, even when two arrive at once.
        if (! $mutex->acquire($lock, 10)) {
            throw new DuplicateReview;
        }

        try {
            $existing = Plugin::getInstance()->reviews->existing((int) $element->id, $user, $user ? null : $email);

            if ($existing && ! $user) {
                throw new DuplicateReview;
            }

            return $this->store($existing, $element, $user, $email, $orders, $form, $siteId);
        } finally {
            $mutex->release($lock);
        }
    }

    protected function form(Request $request, ?User $user, ?Review $existing): ReviewForm
    {
        $form = new ReviewForm;
        $form->signedIn = $user !== null;
        $form->rating = $request->getBodyParam('rating');
        $form->ratings = array_filter((array) $request->getBodyParam('ratings', []), 'is_scalar');
        $form->title = $this->text($request->getBodyParam('title'));
        $form->body = $this->text($request->getBodyParam('body'));
        $form->name = $this->text($request->getBodyParam('name'));
        $form->email = $this->text($request->getBodyParam('email'));
        $form->removePhotos = array_values(array_unique(array_map('intval', array_filter((array) $request->getBodyParam('removePhotos', []), 'is_numeric'))));
        $form->keptPhotos = $existing ? max(0, count($existing->photos) - count($form->removePhotos)) : 0;

        if (Plugin::getInstance()->photos->enabled()) {
            $form->photos = UploadedFile::getInstancesByName('photos');
        }

        return $form;
    }

    /**
     * @param  array<int, int>  $orders
     */
    protected function store(?Review $existing, ElementInterface $element, ?User $user, string $email, array $orders, ReviewForm $form, int $siteId): Review
    {
        $plugin = Plugin::getInstance();
        $review = $existing ?? new Review;
        $review->elementId = (int) $element->id;
        $review->reviewSiteId ??= $siteId;
        $review->rating = (int) $form->rating;
        $review->ratings = $form->criteriaRatings();
        $review->title = $form->title;
        $review->body = $form->body;
        // Signed-in reviewers are shown by their account's name, whatever a
        // form sends.
        $review->name = $user ? $this->userName($user) : (string) $form->name;
        $review->email = $email;
        $review->userId = $user?->id;

        $order = Commerce::isProduct($element) ? Commerce::purchase((int) $element->id, $user, $orders) : null;
        $review->verified = $order !== null;
        $review->orderId = $order;

        // Photo changes are staged: removed ones go only once the review is
        // saved, and new ones are taken away again if it can't be.
        $removed = [];

        if ($existing) {
            foreach ($form->removePhotos as $index) {
                if (isset($review->photos[$index])) {
                    $removed[] = $review->photos[$index];
                    unset($review->photos[$index]);
                }
            }

            $review->photos = array_values($review->photos);
        }

        $kept = $review->photos;

        if ($form->photos !== []) {
            $plugin->photos->store($review, $form->photos);
        }

        $added = array_values(array_filter($review->photos, fn ($photo) => ! in_array($photo, $kept, true)));
        $review->submitting = true;

        try {
            // A changed review goes back to an editor, unless it'd be
            // published straight away anyway.
            if ($this->autoApproves($review)) {
                $review->reviewStatus = $review->isApproved() ? ReviewStatus::Approved : ReviewStatus::Pending;
                $saved = $plugin->reviews->save($review, false) && $plugin->reviews->approve($review);
            } elseif ($existing && ! $review->isPending()) {
                $saved = $plugin->reviews->hold($review);
            } else {
                $review->reviewStatus = ReviewStatus::Pending;
                $saved = $plugin->reviews->save($review, false);
            }
        } catch (\Throwable $e) {
            Craft::error('Reviews couldn’t save a review: '.$e->getMessage(), 'reviews');
            $saved = false;
        }

        if (! $saved) {
            $plugin->photos->deleteFiles($added);
            $review->submitting = false;

            throw new ReviewException(Craft::t('reviews', 'Your review couldn’t be saved. Please try again.'));
        }

        $plugin->photos->deleteFiles($removed);

        if ($plugin->reviews->hasEventHandlers(Reviews::EVENT_AFTER_SUBMIT)) {
            $plugin->reviews->trigger(Reviews::EVENT_AFTER_SUBMIT, new ReviewEvent(['review' => $review]));
        }

        $review->submitting = false;

        return $review;
    }

    /**
     * Whether a review is published without waiting for an editor. Never
     * decided by its rating.
     */
    public function autoApproves(Review $review): bool
    {
        return match (Plugin::getInstance()->getSettings()->autoApprove) {
            'users' => $review->userId !== null,
            'verified' => $review->verified,
            default => false,
        };
    }

    protected function userName(User $user): string
    {
        $name = trim((string) $user->getFullName());

        if ($name === '') {
            return Craft::t('reviews', 'Anonymous');
        }

        return mb_substr($name, 0, max(1, Plugin::getInstance()->getSettings()->nameLimit));
    }

    protected function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim(str_replace("\0", '', (string) $value));

        return $value === '' ? null : $value;
    }
}
