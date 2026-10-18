<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\integrations\Commerce as CommerceIntegration;
use nineteenninetyfour\reviews\migrations\Install;
use nineteenninetyfour\reviews\services\Submissions;

class CommerceTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        // The test site's project config is reset after Commerce installs,
        // which takes its store away: put one back.
        $stores = Commerce::getInstance()->getStores();

        if (! $stores->getPrimaryStore()) {
            $store = new \craft\commerce\models\Store(['name' => 'Dog-ear Books', 'handle' => 'primary', 'primary' => true]);
            $this->assertTrue($stores->saveStore($store), json_encode($store->getErrors()));
            Commerce::getInstance()->set('stores', \craft\commerce\services\Stores::class);
            $stores = Commerce::getInstance()->getStores();

            $siteStore = new \craft\commerce\models\SiteStore(['siteId' => Craft::$app->getSites()->getPrimarySite()->id, 'storeId' => $stores->getPrimaryStore()->id]);
            $stores->saveSiteStore($siteStore);
            Commerce::getInstance()->set('stores', \craft\commerce\services\Stores::class);
        }

        Commerce::getInstance()->set('carts', \craft\commerce\services\Carts::class);

        $this->settings(['productTypes' => ['books'], 'requestsEnabled' => true, 'requestsDelayDays' => 0]);
    }

    protected function productType(): ProductType
    {
        $types = Commerce::getInstance()->getProductTypes();

        if ($existing = $types->getProductTypeByHandle('books')) {
            return $existing;
        }

        $type = new ProductType(['name' => 'Books', 'handle' => 'books', 'skuFormat' => '', 'maxVariants' => 1]);
        $site = new ProductTypeSite([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'hasUrls' => true,
            'uriFormat' => 'shop/{slug}',
            'template' => 'shop/_product',
        ]);
        $type->setSiteSettings([$site->siteId => $site]);

        $this->assertTrue($types->saveProductType($type), json_encode($type->getErrors()));

        return $type;
    }

    protected function product(string $title = 'Moby-Dick', string $sku = 'MOBY-1'): Product
    {
        $product = new Product(['typeId' => $this->productType()->id, 'title' => $title, 'slug' => StringHelper::slugify($title)]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($product), json_encode($product->getErrors()));

        $variant = new Variant(['ownerId' => $product->id, 'primaryOwnerId' => $product->id, 'title' => $title, 'sku' => $sku, 'basePrice' => 12.0, 'isDefault' => true]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($variant), json_encode($variant->getErrors()));

        return Product::find()->id($product->id)->one();
    }

    protected function order(Product $product, ?User $customer = null, string $email = 'buyer@example.com'): Order
    {
        $order = new Order(['number' => Commerce::getInstance()->getCarts()->generateCartNumber()]);
        $order->setEmail($customer->email ?? $email);

        if ($customer) {
            $order->setCustomer($customer);
        }

        $variant = $product->getVariants()->first();
        $order->addLineItem(Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $variant->id, 'qty' => 1]));

        $this->assertTrue(Craft::$app->getElements()->saveElement($order), json_encode($order->getErrors()));
        $this->assertTrue($order->markAsComplete());

        return $order;
    }

    public function test_products_of_reviewed_types_take_reviews(): void
    {
        $product = $this->product();

        $this->assertTrue(CommerceIntegration::isProduct($product));
        $this->assertTrue($this->plugin()->reviews->reviewable($product));

        $this->settings(['productTypes' => []]);
        $this->assertFalse($this->plugin()->reviews->reviewable($product));
    }

    public function test_a_customer_s_purchase_is_found_by_account_or_request_email(): void
    {
        $product = $this->product();
        $customer = $this->user('Bea Buyer');
        $order = $this->order($product, $customer);

        $this->assertSame($order->id, CommerceIntegration::purchase($product->id, $customer));
        $this->assertNull(CommerceIntegration::purchase($product->id, $this->user('Not Buyer')));
        $this->assertNull(CommerceIntegration::purchase($product->id, null));
        $this->assertSame($order->id, CommerceIntegration::purchase($product->id, null, [$order->id]));
        $this->assertNull(CommerceIntegration::purchase($this->product('Other', 'OTHER-1')->id, $customer));
    }

    public function test_buyers_only_refuses_everyone_else(): void
    {
        $this->settings(['buyersOnly' => true]);
        $product = $this->product();
        $customer = $this->user('Bea Buyer');
        $this->order($product, $customer);

        $this->assertSame('buyers_only', $this->plugin()->submissions->refusal($product, $this->user('Someone')));
        $this->assertNull($this->plugin()->submissions->refusal($product, $customer));
    }

    public function test_a_completed_order_asks_for_reviews_once_the_delay_has_passed(): void
    {
        $product = $this->product();
        $order = $this->order($product, null, 'guest@example.com');

        $request = (new Query)->from(Install::REQUESTS)->where(['orderId' => $order->id])->one();
        $this->assertNotNull($request);
        $this->assertSame('guest@example.com', $request['email']);

        $sent = $this->plugin()->requests->send((int) $request['id']);
        $this->assertTrue($sent);

        $this->tester->seeEmailIsSent(1);
        $email = $this->tester->grabLastSentEmail();
        $this->assertArrayHasKey('guest@example.com', $email->getTo());
        $this->assertStringContainsString('Moby-Dick', $email->toString());
        $this->assertStringContainsString('reviews-request=3D'.substr($request['token'], 0, 20), $email->toString());

        // Only once.
        $this->assertFalse($this->plugin()->requests->send((int) $request['id']));
    }

    public function test_the_request_link_makes_a_guest_s_review_verified(): void
    {
        $product = $this->product();
        $order = $this->order($product, null, 'guest@example.com');
        $token = (string) (new Query)->from(Install::REQUESTS)->select('token')->where(['orderId' => $order->id])->scalar();

        // As if the visitor's browser already had a session.
        Craft::$app->getSession()->setHasSessionId(true);
        $this->plugin()->requests->opened($token);
        $this->assertSame([$order->id], Submissions::sessionOrders());

        $this->assertSame($order->id, CommerceIntegration::purchase($product->id, null, Submissions::sessionOrders()));
    }

    public function test_opted_out_customers_aren_t_asked(): void
    {
        $this->plugin()->requests->optOut('Quiet@Example.com');
        $order = $this->order($this->product(), null, 'quiet@example.com');

        $this->assertFalse((new Query)->from(Install::REQUESTS)->where(['orderId' => $order->id])->exists());
        $this->assertTrue($this->plugin()->requests->checkSignature('quiet@example.com', substr(parse_url($this->plugin()->requests->optOutUrl('quiet@example.com'), PHP_URL_QUERY) ?: '', -32)));
    }

    public function test_customers_who_reviewed_already_aren_t_asked_again(): void
    {
        $product = $this->product();
        $order = $this->order($product, null, 'done@example.com');
        $this->plugin()->reviews->save(new Review(['elementId' => $product->id, 'reviewSiteId' => $product->siteId, 'rating' => 5, 'name' => 'Done', 'email' => 'done@example.com']));

        $id = (int) (new Query)->from(Install::REQUESTS)->select('id')->where(['orderId' => $order->id])->scalar();

        $this->assertFalse($this->plugin()->requests->send($id));
    }
}
