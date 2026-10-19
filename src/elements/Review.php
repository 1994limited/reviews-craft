<?php

namespace nineteenninetyfour\reviews\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\actions\Delete;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use NineteenNinetyFour\Reviews\Core\Names\DisplayName;
use NineteenNinetyFour\Reviews\Core\Rating\Stars;
use nineteenninetyfour\reviews\elements\actions\SetReviewStatus;
use nineteenninetyfour\reviews\elements\db\ReviewQuery;
use nineteenninetyfour\reviews\migrations\Install;
use nineteenninetyfour\reviews\Plugin;

/**
 * A review of an entry, a Commerce product, or any other element. Written
 * by a visitor on the site, or imported or synced from another platform.
 * Its title is the reviewer's own heading; editors can reply to a review
 * and moderate it, but never change what the reviewer wrote.
 *
 * @property-read ElementInterface|null $element
 */
class Review extends Element
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SPAM = 'spam';

    /** The element reviewed. */
    public ?int $elementId = null;

    /** The site the review was written on. */
    public ?int $reviewSiteId = null;

    public int $rating = 0;

    /** @var array<string, int> Criteria ratings, by handle. */
    public array $ratings = [];

    public ?string $body = null;

    public string $name = '';

    public ?string $email = null;

    public ?int $userId = null;

    public ReviewStatus $reviewStatus = ReviewStatus::Pending;

    public bool $verified = false;

    public ?int $orderId = null;

    public ?string $reply = null;

    public ?int $replyById = null;

    public ?DateTime $dateReplied = null;

    /** The editors' own note, never shown on the site. */
    public ?string $note = null;

    /**
     * @var array<int, array<string, int|string>> Each photo: ['asset' => id] once
     *                                            published, ['pending' => path] while it waits, or ['url' => address] for an imported one.
     */
    public array $photos = [];

    public int $helpful = 0;

    public ?DateTime $dateApproved = null;

    /**
     * Whether it counts towards the average and the schema: always for this
     * site's own reviews, and for others only when an editor said so.
     */
    public bool $counted = true;

    /** Where it came from: null when written on this site, or a platform's key ("judgeme"). */
    public ?string $source = null;

    /** The sync source it came from, if synced. */
    public ?int $sourceId = null;

    /** Its page on the platform it came from. */
    public ?string $sourceUrl = null;

    /** What tells an imported review apart, so it's never added twice. */
    public ?string $fingerprint = null;

    /** Set while a review is being saved from the form, for event listeners. */
    public bool $submitting = false;

    private ElementInterface|false|null $_element = null;

    public static function displayName(): string
    {
        return Craft::t('reviews', 'Review');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('reviews', 'review');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('reviews', 'Reviews');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('reviews', 'reviews');
    }

    public static function refHandle(): ?string
    {
        return 'review';
    }

    /**
     * The reviewer's heading is the element's title, so it's searched and
     * sorted like any other; reviews without one are fine.
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    protected function shouldValidateTitle(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => ['label' => Craft::t('reviews', 'Pending'), 'color' => 'orange'],
            self::STATUS_APPROVED => ['label' => Craft::t('reviews', 'Approved'), 'color' => 'green'],
            self::STATUS_REJECTED => ['label' => Craft::t('reviews', 'Rejected'), 'color' => 'red'],
            self::STATUS_SPAM => ['label' => Craft::t('reviews', 'Spam'), 'color' => 'gray'],
        ];
    }

    public static function find(): ReviewQuery
    {
        return new ReviewQuery(static::class);
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'Review';
    }

    public static function gqlScopesByContext(mixed $context): array
    {
        return ['reviews'];
    }

    public function getGqlTypeName(): string
    {
        return 'Review';
    }

    public function getStatus(): ?string
    {
        return $this->reviewStatus->value;
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['reviewStatus']) && is_string($values['reviewStatus'])) {
            $values['reviewStatus'] = ReviewStatus::tryFrom($values['reviewStatus']) ?? ReviewStatus::Pending;
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function __construct($config = [])
    {
        if (isset($config['reviewStatus']) && is_string($config['reviewStatus'])) {
            $config['reviewStatus'] = ReviewStatus::tryFrom($config['reviewStatus']) ?? ReviewStatus::Pending;
        }

        foreach (['ratings', 'photos'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = Json::decodeIfJson($config[$key]) ?: [];
            }
        }

        foreach (['verified', 'counted'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = (bool) $config[$key];
            }
        }

        parent::__construct($config);
    }

    // What it's of, and who wrote it.

    public function getElement(): ?ElementInterface
    {
        if ($this->_element === null) {
            $element = $this->elementId ? Craft::$app->getElements()->getElementById($this->elementId, null, $this->reviewSiteId ?? Craft::$app->getSites()->getCurrentSite()->id) : null;
            $this->_element = $element ?? false;
        }

        return $this->_element ?: null;
    }

    public function setElement(?ElementInterface $element): void
    {
        $this->_element = $element ?? false;
        $this->elementId = $element?->id;
    }

    public function getUser(): ?User
    {
        return $this->userId ? User::find()->id($this->userId)->status(null)->one() : null;
    }

    public function getReplyBy(): ?User
    {
        return $this->replyById ? User::find()->id($this->replyById)->status(null)->one() : null;
    }

    /**
     * The reviewer's name as the site shows it: "Jo B." by default.
     */
    public function getDisplayName(): string
    {
        $name = DisplayName::format($this->name, Plugin::getInstance()->getSettings()->nameDisplay);

        return $name !== '' ? $name : Craft::t('reviews', 'Anonymous');
    }

    /**
     * Stars to draw, for the rating or a criterion's.
     *
     * @return array<int, array{type: string, full: bool, half: bool, empty: bool, number: int}>
     */
    public function getStars(?string $criterion = null): array
    {
        return Stars::for($criterion === null ? $this->rating : ($this->ratings[$criterion] ?? null));
    }

    /**
     * Photos on show: published assets, and an imported review's addresses.
     *
     * @return array<int, array{url: string, asset: Asset|null, alt: string}>
     */
    public function getPublicPhotos(): array
    {
        $photos = [];
        $ids = array_values(array_filter(array_map(fn ($p) => $p['asset'] ?? null, $this->photos)));
        $assets = $ids ? Asset::find()->id($ids)->indexBy('id')->all() : [];
        $alt = Craft::t('reviews', 'Photo from {name}’s review', ['name' => $this->getDisplayName()]);

        foreach ($this->photos as $photo) {
            if (isset($photo['asset'], $assets[$photo['asset']]) && ($url = $assets[$photo['asset']]->getUrl())) {
                $photos[] = ['url' => $url, 'asset' => $assets[$photo['asset']], 'alt' => $assets[$photo['asset']]->alt ?: $alt];
            } elseif (isset($photo['url']) && is_string($photo['url'])) {
                $photos[] = ['url' => $photo['url'], 'asset' => null, 'alt' => $alt];
            }
        }

        return $photos;
    }

    public function hasPendingPhotos(): bool
    {
        foreach ($this->photos as $photo) {
            if (isset($photo['pending'])) {
                return true;
            }
        }

        return false;
    }

    public function isApproved(): bool
    {
        return $this->reviewStatus === ReviewStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->reviewStatus === ReviewStatus::Pending;
    }

    /** Written on this site, rather than imported or synced. */
    public function isOwn(): bool
    {
        return $this->source === null;
    }

    // Saving.

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['elementId', 'reviewSiteId', 'rating'], 'required'];
        $rules[] = [['rating'], 'integer', 'min' => 1, 'max' => 5];
        $rules[] = [['email'], 'email'];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        $columns = [
            'elementId' => $this->elementId,
            'reviewSiteId' => $this->reviewSiteId,
            'rating' => $this->rating,
            'ratings' => $this->ratings ? Json::encode($this->ratings) : null,
            'body' => $this->body,
            'name' => $this->name,
            'email' => $this->email !== null ? mb_strtolower($this->email) : null,
            'userId' => $this->userId,
            'reviewStatus' => $this->reviewStatus->value,
            'verified' => $this->verified,
            'orderId' => $this->orderId,
            'reply' => $this->reply,
            'replyById' => $this->replyById,
            'dateReplied' => Db::prepareDateForDb($this->dateReplied),
            'note' => $this->note,
            'photos' => $this->photos ? Json::encode(array_values($this->photos)) : null,
            'dateApproved' => Db::prepareDateForDb($this->dateApproved),
            'counted' => $this->counted,
            'source' => $this->source,
            'sourceId' => $this->sourceId,
            'sourceUrl' => $this->sourceUrl,
            'fingerprint' => $this->fingerprint,
        ];

        if ($isNew) {
            Db::insert(Install::REVIEWS, ['id' => $this->id, 'helpful' => $this->helpful] + $columns);
        } else {
            // The helpful count belongs to the votes: a review loaded before
            // someone voted mustn't put the old count back.
            Db::update(Install::REVIEWS, $columns, ['id' => $this->id]);
        }

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        Plugin::reviewDeleted($this);

        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        Plugin::getInstance()->reviews->changed((int) $this->elementId);

        parent::afterRestore();
    }

    // The control panel.

    public function canView(User $user): bool
    {
        return parent::canView($user) || $user->can(Plugin::PERMISSION_MODERATE);
    }

    public function canSave(User $user): bool
    {
        return parent::canSave($user) || $user->can(Plugin::PERMISSION_MODERATE);
    }

    public function canDelete(User $user): bool
    {
        return parent::canDelete($user) || $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    protected function cpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('reviews/'.$this->id) : null;
    }

    public function getUiLabel(): string
    {
        $label = $this->title !== null && $this->title !== '' ? $this->title : mb_strimwidth((string) $this->body, 0, 60, '…');

        return $label !== '' ? $label : Craft::t('reviews', '{rating}-star review', ['rating' => $this->rating]);
    }

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('reviews', 'All reviews'),
                'criteria' => ['status' => null],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'pending',
                'label' => Craft::t('reviews', 'Waiting for moderation'),
                'criteria' => ['status' => self::STATUS_PENDING],
                'defaultSort' => ['dateCreated', 'asc'],
                'badgeCount' => (int) Review::find()->status(self::STATUS_PENDING)->count(),
            ],
            ['heading' => Craft::t('reviews', 'Written')],
            [
                'key' => 'own',
                'label' => Craft::t('reviews', 'On this site'),
                'criteria' => ['status' => null, 'source' => ':empty:'],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'imported',
                'label' => Craft::t('reviews', 'Imported or synced'),
                'criteria' => ['status' => null, 'source' => ':notempty:'],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        return $sources;
    }

    protected static function defineActions(string $source): array
    {
        return [
            ['type' => SetReviewStatus::class, 'status' => self::STATUS_APPROVED],
            ['type' => SetReviewStatus::class, 'status' => self::STATUS_REJECTED],
            ['type' => SetReviewStatus::class, 'status' => self::STATUS_SPAM],
            Delete::class,
        ];
    }

    protected static function includeSetStatusAction(): bool
    {
        return false;
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['body', 'name', 'email', 'reply'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            'rating' => Craft::t('reviews', 'Rating'),
            'helpful' => Craft::t('reviews', 'Helpful'),
            'name' => Craft::t('reviews', 'Name'),
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'rating' => ['label' => Craft::t('reviews', 'Rating')],
            'element' => ['label' => Craft::t('reviews', 'Of')],
            'name' => ['label' => Craft::t('reviews', 'Name')],
            'email' => ['label' => Craft::t('reviews', 'Email')],
            'verified' => ['label' => Craft::t('reviews', 'Verified')],
            'photos' => ['label' => Craft::t('reviews', 'Photos')],
            'helpful' => ['label' => Craft::t('reviews', 'Helpful')],
            'source' => ['label' => Craft::t('reviews', 'Source')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateApproved' => ['label' => Craft::t('reviews', 'Date Approved')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['rating', 'element', 'name', 'verified', 'dateCreated'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'rating' => Html::tag('span', str_repeat('★', $this->rating).str_repeat('☆', 5 - $this->rating), ['title' => Craft::t('reviews', '{rating} out of 5', ['rating' => $this->rating]), 'style' => 'color: #e5a00d; letter-spacing: 1px;']),
            'element' => ($element = $this->getElement()) ? Cp::elementChipHtml($element) : '',
            'name' => Html::encode($this->name ?: Craft::t('reviews', 'Anonymous')),
            'email' => $this->email ? Html::a(Html::encode($this->email), 'mailto:'.$this->email) : '',
            'verified' => $this->verified ? Html::tag('span', '', ['data-icon' => 'check', 'title' => Craft::t('reviews', 'Verified buyer')]) : '',
            'photos' => $this->photos ? (string) count($this->photos) : '',
            'helpful' => $this->helpful ? (string) $this->helpful : '',
            'source' => Html::encode($this->source === null ? Craft::t('reviews', 'This site') : Plugin::getInstance()->imports->sourceLabel($this->source)),
            default => parent::attributeHtml($attribute),
        };
    }
}
