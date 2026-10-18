<?php

namespace nineteenninetyfour\reviews\integrations;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin;
use craft\elements\User;
use Throwable;

/**
 * Craft Commerce, when it's installed: which products take reviews, and
 * whether someone bought the one they're reviewing. Nothing here touches a
 * Commerce class unless Commerce is there.
 */
class Commerce
{
    public static function installed(): bool
    {
        return class_exists(Plugin::class) && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public static function isProduct(mixed $element): bool
    {
        return self::installed() && $element instanceof Product;
    }

    /**
     * The product type handles, as handle => name, for settings.
     *
     * @return array<string, string>
     */
    public static function productTypes(): array
    {
        if (! self::installed()) {
            return [];
        }

        $types = [];

        foreach (Plugin::getInstance()->getProductTypes()->getAllProductTypes() as $type) {
            $types[$type->handle] = $type->name;
        }

        return $types;
    }

    public static function productTypeHandle(ElementInterface $product): ?string
    {
        try {
            return $product instanceof Product ? $product->getType()->handle : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ID of a completed order in which someone bought the product: one
     * on their account, or one they opened a review request email for. A
     * guest's typed email never counts: anyone can type someone else's.
     *
     * @param  array<int, int>  $orderIds  Orders opened from review request emails this visit.
     */
    public static function purchase(int $productId, ?User $user, array $orderIds = []): ?int
    {
        if (! self::installed()) {
            return null;
        }

        try {
            $variants = Variant::find()->productId($productId)->status(null)->ids();

            if ($variants === []) {
                return null;
            }

            $query = fn () => Order::find()->isCompleted()->hasPurchasables($variants)->orderBy(['dateOrdered' => SORT_DESC]);

            if ($orderIds !== []) {
                $id = $query()->id($orderIds)->ids()[0] ?? null;

                if ($id) {
                    return (int) $id;
                }
            }

            if ($user) {
                $id = $query()->customerId($user->id)->ids()[0] ?? null;

                if ($id) {
                    return (int) $id;
                }
            }
        } catch (Throwable $e) {
            Craft::warning('Reviews couldn’t check Commerce orders: '.$e->getMessage(), 'reviews');
        }

        return null;
    }

    /**
     * The products bought in an order, for a review request email.
     *
     * @return array<int, ElementInterface>
     */
    public static function orderProducts(int $orderId): array
    {
        if (! self::installed()) {
            return [];
        }

        $order = Order::find()->id($orderId)->one();
        $products = [];

        foreach ($order?->getLineItems() ?? [] as $item) {
            $purchasable = $item->getPurchasable();
            $product = $purchasable instanceof Variant ? $purchasable->getProduct() : null;

            if ($product && ! isset($products[$product->id])) {
                $products[$product->id] = $product;
            }
        }

        return array_values($products);
    }

    /**
     * @return array{email: string|null, name: string, status: string|null, siteId: int|null}|null
     */
    public static function order(int $orderId): ?array
    {
        if (! self::installed()) {
            return null;
        }

        $order = Order::find()->id($orderId)->one();

        if (! $order) {
            return null;
        }

        $address = $order->getBillingAddress();

        return [
            'email' => $order->getEmail(),
            'name' => trim((string) ($address->firstName ?? $order->getCustomer()->firstName ?? '')),
            'status' => $order->getOrderStatus()?->handle,
            'siteId' => $order->orderSiteId,
        ];
    }
}
