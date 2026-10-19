<?php

namespace nineteenninetyfour\reviews\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\models\ReviewForm;
use nineteenninetyfour\reviews\Plugin;
use nineteenninetyfour\reviews\services\DuplicateReview;
use nineteenninetyfour\reviews\services\ReviewException;
use yii\web\Cookie;
use yii\web\Response;

/**
 * What visitors do on the site: write a review, change or delete their
 * own, mark one helpful, and opt out of review request emails. Each answers
 * with JSON when asked for it, or else redirects with a message.
 */
class ReviewsController extends Controller
{
    public const VOTER_COOKIE = 'reviews_voter';

    protected array|bool|int $allowAnonymous = ['submit', 'vote', 'opt-out'];

    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $request = $this->request;

        $user = static::currentUser();

        // A guest always gets the same answer, whether their review was
        // kept, was a repeat, or looked like a bot's: nothing in it says
        // whether an email address has reviewed this before.
        $thanks = Craft::t('reviews', 'Thank you for your review.');

        if ($plugin->spam->isBot($request)) {
            return $this->succeeded($thanks);
        }

        if ($this->limited('submit', $plugin->getSettings()->throttleSubmit, 600)) {
            return $this->tooMany();
        }

        if (! $plugin->spam->captchaPassed($request)) {
            $form = new ReviewForm;
            $form->addError('captcha', Craft::t('reviews', 'Please show you’re not a robot.'));

            return $this->failed($form);
        }

        try {
            $result = $plugin->submissions->submit($request, $user);
        } catch (DuplicateReview) {
            return $this->succeeded($thanks);
        } catch (ReviewException $e) {
            $form = new ReviewForm;
            $form->addError('rating', $e->getMessage());

            return $this->failed($form);
        }

        if ($result instanceof ReviewForm) {
            return $this->failed($result);
        }

        if (! $user) {
            return $this->succeeded($thanks);
        }

        return $this->succeeded(
            $result->isApproved() ? Craft::t('reviews', 'Thank you! Your review is up.') : Craft::t('reviews', 'Thank you! Your review will appear once it’s been checked.'),
            ['review' => ['id' => $result->id, 'status' => $result->getStatus()]],
        );
    }

    /**
     * A signed-in reviewer deleting their own review.
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $user = static::currentUser();
        $review = Review::find()->id((int) $this->request->getRequiredBodyParam('reviewId'))->status(null)->one();

        if (! $user || ! $review || $review->userId !== $user->id) {
            return $this->asFailure(Craft::t('reviews', 'That review couldn’t be found.'));
        }

        Plugin::getInstance()->reviews->delete($review);

        return $this->succeeded(Craft::t('reviews', 'Your review has been deleted.'));
    }

    /**
     * "Was this helpful?" One vote per visitor: their account, or a cookie
     * for guests.
     */
    public function actionVote(): ?Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        if ($this->limited('votes', $plugin->getSettings()->throttleVotes, 60)) {
            return $this->tooMany();
        }

        $review = Review::find()->id((int) $this->request->getRequiredBodyParam('reviewId'))->one();

        if (! $plugin->getSettings()->votesEnabled || ! $review || ! $review->isOwn()) {
            return $this->asFailure(Craft::t('reviews', 'That review couldn’t be found.'));
        }

        $voted = $plugin->reviews->vote($review, $this->voter());

        return $this->succeeded($voted ? Craft::t('reviews', 'Thanks for letting us know.') : Craft::t('reviews', 'You’ve said this review was helpful already.'), ['helpful' => $review->helpful]);
    }

    /**
     * No more review request emails. The link shows a page with a button, so
     * a mail scanner following it doesn't opt anyone out.
     */
    public function actionOptOut(): Response
    {
        $requests = Plugin::getInstance()->requests;
        $token = (string) $this->request->getParam('request', '');

        if ($token !== '') {
            // From 1.0.1: the request's own token, so the address isn't in
            // the link.
            $email = (string) $requests->emailForToken($token);
            $valid = $email !== '';
            $params = ['request' => $token];
        } else {
            // Links in emails sent by 1.0.0.
            $email = (string) $this->request->getParam('email', '');
            $signature = (string) $this->request->getParam('sig', '');
            $valid = $email !== '' && $requests->checkSignature($email, $signature);
            $params = ['email' => $email, 'sig' => $signature];
        }

        $done = false;

        if ($valid && $this->request->getIsPost()) {
            $requests->optOut($email);
            $done = true;
        }

        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex');

        return $this->renderTemplate('reviews/_opt-out', [
            'email' => $email,
            'valid' => $valid,
            'done' => $done,
            'params' => $params,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Whether a visitor has done this too often lately: counted by their
     * address, hashed, in the cache, for as long as the limit lasts.
     */
    protected function limited(string $what, int $max, int $seconds): bool
    {
        $key = 'reviews:limit:'.$what.':'.hash_hmac('sha256', (string) $this->request->getUserIP(), (string) Craft::$app->getConfig()->getGeneral()->securityKey);
        $cache = Craft::$app->getCache();
        $hits = $cache->get($key);
        $hits = is_array($hits) && $hits['until'] > time() ? $hits : ['count' => 0, 'until' => time() + $seconds];
        $hits['count']++;
        $cache->set($key, $hits, max(1, $hits['until'] - time()));

        return $hits['count'] > max(1, $max);
    }

    protected function tooMany(): ?Response
    {
        $message = Craft::t('reviews', 'That’s a lot at once. Please wait a few minutes and try again.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => false, 'message' => $message])->setStatusCode(429);
        }

        $form = new ReviewForm;
        $form->addError('rating', $message);
        $this->response->setStatusCode(429);

        return $this->failed($form);
    }

    protected function voter(): string
    {
        $key = (string) Craft::$app->getConfig()->getGeneral()->securityKey;

        if ($user = static::currentUser()) {
            return substr(hash_hmac('sha256', 'u:'.$user->id, $key), 0, 64);
        }

        $token = $this->request->getCookies()->getValue(self::VOTER_COOKIE);

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{32}$/', $token)) {
            // A first vote, or someone who drops cookies: the same browser on
            // the same connection gets the same token, so dropping the cookie
            // doesn't make a new voter.
            $token = substr(hash_hmac('sha256', 'voter|'.$this->request->getUserIP().'|'.$this->request->getUserAgent().'|'.$this->request->getHeaders()->get('Accept-Language'), $key), 0, 32);
            $this->response->getCookies()->add(new Cookie([
                'name' => self::VOTER_COOKIE,
                'value' => $token,
                'expire' => time() + 365 * 86400,
                'httpOnly' => true,
                'sameSite' => Cookie::SAME_SITE_LAX,
                'secure' => $this->request->getIsSecureConnection(),
            ]));
        }

        return substr(hash_hmac('sha256', 'g:'.$token, $key), 0, 64);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function succeeded(string $message, array $data = []): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message] + $data);
        }

        Craft::$app->getSession()->setFlash('reviews', $message);

        return $this->redirectToPostedUrl(null, $this->request->getReferrer() ?? '/');
    }

    protected function failed(ReviewForm $form): ?Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => false, 'errors' => $form->getErrors()])->setStatusCode(400);
        }

        Craft::$app->getUrlManager()->setRouteParams(['reviewForm' => $form]);

        return null;
    }
}
