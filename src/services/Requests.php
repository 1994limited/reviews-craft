<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateInterval;
use DateTime;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\integrations\Commerce;
use nineteenninetyfour\reviews\jobs\SendReviewRequest;
use nineteenninetyfour\reviews\migrations\Install;
use nineteenninetyfour\reviews\Plugin;
use Throwable;

/**
 * Review request emails: some days after an order, the customer is asked to
 * review what they bought. The links in the email remember the order, so
 * the review is marked as a verified purchase even for a guest.
 */
class Requests extends Component
{
    public const MESSAGE = 'reviews_request';

    public const PARAM = 'reviews-request';

    /** How long to keep waiting for an order to reach the status asked for. */
    public const GIVE_UP_DAYS = 60;

    /** How long a request email's links count as coming from the customer. */
    public const TOKEN_DAYS = 90;

    /**
     * An order was completed: ask for reviews once the delay has passed.
     */
    public function orderCompleted(int $orderId): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $order = Commerce::order($orderId);

        if (! $settings->requestsEnabled || ! $order || ! $order['email']) {
            return;
        }

        if ($this->optedOut($order['email']) || (new Query)->from(Install::REQUESTS)->where(['orderId' => $orderId])->exists()) {
            return;
        }

        $delay = max(0, $settings->requestsDelayDays) * 86400;

        Db::insert(Install::REQUESTS, [
            'orderId' => $orderId,
            'email' => mb_strtolower($order['email']),
            'token' => StringHelper::randomString(40),
            'siteId' => $order['siteId'],
            'dateDue' => Db::prepareDateForDb((new DateTime)->add(new DateInterval('PT'.$delay.'S'))),
        ]);

        $id = (int) Craft::$app->getDb()->getLastInsertID(Install::REQUESTS);

        Craft::$app->getQueue()->delay($delay)->push(new SendReviewRequest(['requestId' => $id]));
    }

    /**
     * Send a request, if it's due and there's something left to review.
     * Returns whether an email went.
     */
    public function send(int $requestId): bool
    {
        $request = (new Query)->from(Install::REQUESTS)->where(['id' => $requestId])->one();

        if (! $request || $request['dateSent'] !== null) {
            return false;
        }

        $due = new DateTime($request['dateDue'], new \DateTimeZone('UTC'));

        if ($due > new DateTime) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $order = Commerce::order((int) $request['orderId']);

        if (! $order) {
            $this->markSent($requestId);

            return false;
        }

        // Not shipped yet, say: look again tomorrow, for a while.
        if ($settings->requestsOrderStatuses !== [] && ! in_array($order['status'], $settings->requestsOrderStatuses, true)) {
            if ((new DateTime)->getTimestamp() - $due->getTimestamp() < self::GIVE_UP_DAYS * 86400) {
                Craft::$app->getQueue()->delay(86400)->push(new SendReviewRequest(['requestId' => $requestId]));
            } else {
                $this->markSent($requestId);
            }

            return false;
        }

        $products = $this->products((int) $request['orderId'], (string) $request['email'], $request['siteId'] ? (int) $request['siteId'] : null);

        if ($products === [] || $this->optedOut((string) $request['email'])) {
            $this->markSent($requestId);

            return false;
        }

        $links = array_map(fn (ElementInterface $product) => [
            'title' => (string) $product->title,
            'url' => UrlHelper::urlWithParams((string) $product->getUrl(), [self::PARAM => $request['token']]).'#reviews-form',
            'product' => $product,
        ], $products);

        // Claim it first: two workers sending the same request at once can't
        // both send it.
        $claimed = Db::update(Install::REQUESTS, ['dateSent' => Db::prepareDateForDb(new DateTime)], ['id' => $requestId, 'dateSent' => null]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $sent = Craft::$app->getMailer()
                ->composeFromKey(self::MESSAGE, [
                    'name' => $order['name'],
                    'products' => $links,
                    'orderId' => (int) $request['orderId'],
                    'optOutUrl' => $this->optOutUrl((string) $request['email']),
                ])
                ->setTo((string) $request['email'])
                ->send();
        } catch (Throwable $e) {
            Craft::error('Reviews couldn’t send a review request: '.$e->getMessage(), 'reviews');
            $sent = false;
        }

        if (! $sent) {
            // Not sent after all: free it, to try again.
            Db::update(Install::REQUESTS, ['dateSent' => null], ['id' => $requestId]);
        }

        return (bool) $sent;
    }

    /**
     * The order's products that take reviews and that the customer hasn't
     * reviewed yet.
     *
     * @return array<int, ElementInterface>
     */
    protected function products(int $orderId, string $email, ?int $siteId): array
    {
        $reviews = Plugin::getInstance()->reviews;
        $products = [];

        foreach (Commerce::orderProducts($orderId) as $product) {
            if ($siteId) {
                $product = Craft::$app->getElements()->getElementById((int) $product->id, $product::class, $siteId) ?? $product;
            }

            if ($reviews->reviewable($product) && ! Review::find()->elementId($product->id)->email($email)->status(null)->exists()) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Someone opened a link from a request email: remember the order for
     * this visit, so their review counts as a verified purchase.
     */
    public function opened(string $token): void
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return;
        }

        $request = (new Query)->from(Install::REQUESTS)->where(['token' => $token])->one();

        // Only for a while after the email went: a link found later, in a
        // forwarded email or an old history, doesn't make anyone a buyer.
        if (! $request || $request['dateSent'] === null || (new DateTime($request['dateSent'], new \DateTimeZone('UTC')))->getTimestamp() < time() - self::TOKEN_DAYS * 86400) {
            return;
        }

        Submissions::rememberOrder((int) $request['orderId']);

        if ($request['dateOpened'] === null) {
            Db::update(Install::REQUESTS, ['dateOpened' => Db::prepareDateForDb(new DateTime)], ['id' => $request['id']]);
        }
    }

    /**
     * Send every request that's due and unsent: for sites whose queue lost
     * a job, or that would rather send from cron.
     */
    public function sendDue(): int
    {
        $sent = 0;
        $ids = (new Query)->from(Install::REQUESTS)->select('id')->where(['dateSent' => null])->andWhere(['<=', 'dateDue', Db::prepareDateForDb(new DateTime)])->column();

        foreach ($ids as $id) {
            $sent += $this->send((int) $id) ? 1 : 0;
        }

        return $sent;
    }

    /**
     * The link to ask for no more review request emails. Signed, so nobody
     * can opt someone else out.
     */
    public function optOutUrl(string $email): string
    {
        $email = mb_strtolower($email);

        return UrlHelper::actionUrl('reviews/reviews/opt-out', ['email' => $email, 'sig' => $this->signature($email)]);
    }

    public function checkSignature(string $email, string $signature): bool
    {
        return hash_equals($this->signature(mb_strtolower($email)), $signature);
    }

    public function optOut(string $email): void
    {
        if (! $this->optedOut($email)) {
            Db::insert(Install::OPT_OUTS, ['emailHash' => $this->hashEmail($email)]);
        }
    }

    public function optedOut(string $email): bool
    {
        return (new Query)->from(Install::OPT_OUTS)->where(['emailHash' => $this->hashEmail($email)])->exists();
    }

    protected function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    protected function signature(string $email): string
    {
        return substr(hash_hmac('sha256', 'reviews-opt-out|'.$email, (string) Craft::$app->getConfig()->getGeneral()->securityKey), 0, 32);
    }

    protected function markSent(int $requestId): void
    {
        Db::update(Install::REQUESTS, ['dateSent' => Db::prepareDateForDb(new DateTime)], ['id' => $requestId]);
    }
}
