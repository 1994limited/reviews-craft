<?php

namespace nineteenninetyfour\reviews;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\elements\User;
use craft\events\DeleteElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\events\ReviewEvent;
use nineteenninetyfour\reviews\fields\ReviewsField;
use nineteenninetyfour\reviews\gql\ReviewGql;
use nineteenninetyfour\reviews\integrations\Seomatic;
use nineteenninetyfour\reviews\models\Settings;
use nineteenninetyfour\reviews\services\Imports;
use nineteenninetyfour\reviews\services\Photos;
use nineteenninetyfour\reviews\services\Requests;
use nineteenninetyfour\reviews\services\Reviews;
use nineteenninetyfour\reviews\services\Spam;
use nineteenninetyfour\reviews\services\Submissions;
use nineteenninetyfour\reviews\services\Sync;
use nineteenninetyfour\reviews\variables\ReviewsVariable;
use nineteenninetyfour\reviews\widgets\PendingReviews;
use Throwable;
use yii\base\Event;

/**
 * Reviews for Craft CMS.
 *
 * @property-read Reviews $reviews
 * @property-read Submissions $submissions
 * @property-read Photos $photos
 * @property-read Spam $spam
 * @property-read Imports $imports
 * @property-read Sync $sync
 * @property-read Requests $requests
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_MODERATE = 'reviews-moderate';

    public const PERMISSION_DELETE = 'reviews-delete';

    public const PERMISSION_IMPORT = 'reviews-import';

    public string $schemaVersion = '1.0.1';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'reviews' => Reviews::class,
                'submissions' => Submissions::class,
                'photos' => Photos::class,
                'spam' => Spam::class,
                'imports' => Imports::class,
                'sync' => Sync::class,
                'requests' => Requests::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElements();
        $this->registerTwig();
        $this->registerPermissions();
        $this->registerEmails();
        $this->registerGraphQl();
        $this->registerCommerce();
        Seomatic::register();

        Craft::$app->onInit(function () {
            $request = Craft::$app->getRequest();

            if ($request->getIsConsoleRequest()) {
                return;
            }

            if ($request->getIsCpRequest()) {
                $this->registerCp();
            } elseif ($request->getIsSiteRequest()) {
                $this->registerSite();
            }
        });
    }

    // Settings.

    protected function createSettingsModel(): ?Model
    {
        return new Settings;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('reviews/settings'));
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        if (! $user->checkPermission(self::PERMISSION_MODERATE)) {
            return null;
        }

        $pending = (int) Review::find()->status(Review::STATUS_PENDING)->count();

        if ($pending > 0) {
            $item['badgeCount'] = $pending;
        }

        $item['subnav'] = [
            'reviews' => ['label' => Craft::t('reviews', 'Reviews'), 'url' => 'reviews'],
        ];

        if ($user->checkPermission(self::PERMISSION_IMPORT)) {
            $item['subnav']['import'] = ['label' => Craft::t('reviews', 'Import'), 'url' => 'reviews/import'];
            $item['subnav']['sources'] = ['label' => Craft::t('reviews', 'Sync'), 'url' => 'reviews/sources'];
        }

        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges && $user->getIsAdmin()) {
            $item['subnav']['settings'] = ['label' => Craft::t('reviews', 'Settings'), 'url' => 'reviews/settings'];
        }

        return $item;
    }

    // Registration.

    protected function registerElements(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = Review::class;
        });

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ReviewsField::class;
        });

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = PendingReviews::class;
        });
    }

    protected function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            $event->sender->set('reviews', ReviewsVariable::class);
        });

        // The ready-made templates, at "_reviews/...". A site's own
        // templates/_reviews folder takes their place, file by file.
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event) {
            $event->roots['_reviews'] = __DIR__.'/templates/_site';
        });
    }

    protected function registerSite(): void
    {
        $request = Craft::$app->getRequest();
        $token = $request->getQueryParam(Requests::PARAM);

        if (is_string($token) && $token !== '') {
            try {
                $this->requests->opened($token);
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t read a review request link: '.$e->getMessage(), 'reviews');
            }
        }

        if ($this->getSettings()->schemaOutput === 'auto') {
            Event::on(View::class, View::EVENT_END_BODY, function () {
                $element = Craft::$app->getUrlManager()->getMatchedElement();

                if ($element && $this->reviews->reviewable($element)) {
                    echo (new ReviewsVariable)->schema($element);
                }
            });
        }
    }

    protected function registerCp(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules['reviews'] = ['template' => 'reviews/_index'];
            $event->rules['reviews/<reviewId:\d+>'] = 'reviews/moderate/edit';
            $event->rules['reviews/import'] = 'reviews/import/index';
            $event->rules['reviews/sources'] = 'reviews/sources/index';
            $event->rules['reviews/sources/new'] = 'reviews/sources/edit';
            $event->rules['reviews/sources/<sourceId:\d+>'] = 'reviews/sources/edit';
            $event->rules['reviews/settings'] = 'reviews/settings/edit';
        });

        // Sources that are due are synced in the queue, whenever someone's
        // in the control panel; a cron job running `craft reviews/sync`
        // keeps them up to date when nobody is.
        Craft::$app->onAfterRequest(function () {
            try {
                if (! Craft::$app->getUser()->getIsGuest()) {
                    $this->sync->queueDue();
                }
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t check for syncs: '.$e->getMessage(), 'reviews');
            }
        });
    }

    /**
     * Always, not only in the control panel: saving permissions anywhere
     * keeps only those registered.
     */
    protected function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('reviews', 'Reviews'),
                'permissions' => [
                    self::PERMISSION_MODERATE => [
                        'label' => Craft::t('reviews', 'Moderate and reply to reviews'),
                        'nested' => [
                            self::PERMISSION_DELETE => ['label' => Craft::t('reviews', 'Delete reviews')],
                            self::PERMISSION_IMPORT => ['label' => Craft::t('reviews', 'Import and sync reviews')],
                        ],
                    ],
                ],
            ];
        });
    }

    protected function registerEmails(): void
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function (RegisterEmailMessagesEvent $event) {
            $event->messages[] = [
                'key' => Requests::MESSAGE,
                'heading' => Craft::t('reviews', 'When a customer is asked to review their order'),
                'subject' => Craft::t('reviews', 'How was your order?'),
                'body' => Craft::t('reviews', "Hi{% if name %} {{ name }}{% endif %},\n\nThank you for your order. We’d love to know what you think of it:\n\n{% for product in products %}- [{{ product.title }}]({{ product.url }})\n{% endfor %}\nYour review helps other customers, and us.\n\nThank you,\n{{ systemName }}\n\n[No more emails like this]({{ optOutUrl }})"),
            ];
            $event->messages[] = [
                'key' => 'reviews_approved',
                'heading' => Craft::t('reviews', 'When a reviewer’s review goes up'),
                'subject' => Craft::t('reviews', 'Your review of {{ element.title }} is up'),
                'body' => Craft::t('reviews', "Hi {{ review.name }},\n\nThank you for reviewing {{ element.title }}. Your review is on the site now:\n\n{{ element.url }}#review-{{ review.id }}\n\n{{ systemName }}"),
            ];
            $event->messages[] = [
                'key' => 'reviews_replied',
                'heading' => Craft::t('reviews', 'When the site replies to a review'),
                'subject' => Craft::t('reviews', 'We replied to your review of {{ element.title }}'),
                'body' => Craft::t('reviews', "Hi {{ review.name }},\n\nWe’ve replied to your review of {{ element.title }}:\n\n> {{ review.reply }}\n\n{{ element.url }}#review-{{ review.id }}\n\n{{ systemName }}"),
            ];
            $event->messages[] = [
                'key' => 'reviews_new',
                'heading' => Craft::t('reviews', 'When a review is written'),
                'subject' => Craft::t('reviews', 'New {{ review.rating }}-star review of {{ element.title }}'),
                'body' => Craft::t('reviews', "{{ review.name }} wrote a {{ review.rating }}-star review of {{ element.title }}{% if review.title %}: “{{ review.title }}”{% endif %}.\n\n{% if review.status == 'pending' %}It’s waiting for you to approve it: {% else %}See it: {% endif %}{{ review.cpEditUrl }}"),
            ];
        });

        Event::on(Reviews::class, Reviews::EVENT_AFTER_APPROVE, function (ReviewEvent $event) {
            if ($this->getSettings()->notifyApproved) {
                $this->tellReviewer('reviews_approved', $event->review);
            }
        });

        Event::on(Reviews::class, Reviews::EVENT_AFTER_REPLY, function (ReviewEvent $event) {
            if ($this->getSettings()->notifyReplied && $event->review->isApproved()) {
                $this->tellReviewer('reviews_replied', $event->review);
            }
        });

        // A reviewed element deleted for good takes its reviews with it, as
        // elements, so their photos go too. Craft's garbage collection
        // deletes trashed elements without events: whatever that leaves is
        // swept up as it finishes.
        Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, function (DeleteElementEvent $event) {
            if ($event->hardDelete && ! $event->element instanceof Review) {
                $this->reviews->deleteFor((int) $event->element->id);
            }
        });

        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $this->reviews->deleteOrphans();
        });

        // A deleted user's reviews lose their name and email, or go too.
        Event::on(User::class, Element::EVENT_AFTER_DELETE, function (Event $event) {
            /** @var User $user */
            $user = $event->sender;

            try {
                $this->reviews->forget($user->email, (int) $user->id, $this->getSettings()->deletedUsers === 'delete');
            } catch (Throwable $e) {
                Craft::error('Reviews couldn’t forget a deleted user’s reviews: '.$e->getMessage(), 'reviews');
            }
        });

        Event::on(Reviews::class, Reviews::EVENT_AFTER_SUBMIT, function (ReviewEvent $event) {
            $notify = array_filter($this->getSettings()->notify);

            if ($notify === []) {
                return;
            }

            try {
                Craft::$app->getMailer()
                    ->composeFromKey('reviews_new', ['review' => $event->review, 'element' => $event->review->getElement()])
                    ->setTo($notify)
                    ->send();
            } catch (Throwable $e) {
                Craft::warning('Reviews couldn’t send the new review email: '.$e->getMessage(), 'reviews');
            }
        });
    }

    protected function registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function (RegisterGqlTypesEvent $event) {
            $event->types[] = ReviewGql::interface();
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function (RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, ReviewGql::queries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function (RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[Craft::t('reviews', 'Reviews')] = [
                'reviews:read' => ['label' => Craft::t('reviews', 'View approved reviews')],
            ];
        });
    }

    protected function registerCommerce(): void
    {
        if (! class_exists(Order::class)) {
            return;
        }

        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, function (Event $event) {
            try {
                $this->requests->orderCompleted((int) $event->sender->id);
            } catch (Throwable $e) {
                // A review request mustn't get in the way of an order.
                Craft::error('Reviews couldn’t schedule a review request: '.$e->getMessage(), 'reviews');
            }
        });
    }

    /**
     * Email the person who wrote a review on this site.
     */
    protected function tellReviewer(string $message, Review $review): void
    {
        $element = $review->getElement();

        if (! $review->isOwn() || ! $review->email || ! $element) {
            return;
        }

        try {
            $mail = Craft::$app->getMailer()->composeFromKey($message, ['review' => $review, 'element' => $element])->setTo($review->email);
            // In the language of the site it was written on.
            $mail->siteId = $review->reviewSiteId;
            $mail->send();
        } catch (Throwable $e) {
            Craft::warning('Reviews couldn’t email a reviewer: '.$e->getMessage(), 'reviews');
        }
    }

    /**
     * A review's photos go with it, when it's deleted for good.
     */
    public static function reviewDeleted(Review $review): void
    {
        if ($review->hardDelete) {
            self::getInstance()->photos->delete($review);
        }

        self::getInstance()->reviews->changed((int) $review->elementId);
    }
}
