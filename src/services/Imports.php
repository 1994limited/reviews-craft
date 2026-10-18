<?php

namespace nineteenninetyfour\reviews\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\Entry;
use DateTime;
use NineteenNinetyFour\Reviews\Core\Import\Formats;
use NineteenNinetyFour\Reviews\Core\Import\ImportedReview;
use NineteenNinetyFour\Reviews\Core\Import\Importer;
use NineteenNinetyFour\Reviews\Core\Import\ImportResult;
use NineteenNinetyFour\Reviews\Core\Import\Values;
use NineteenNinetyFour\Reviews\Core\Sync\Sources;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\integrations\Commerce;
use nineteenninetyfour\reviews\models\ImportOptions;
use nineteenninetyfour\reviews\models\ImportReport;
use nineteenninetyfour\reviews\Plugin;
use Throwable;

/**
 * Adding reviews from another platform, from a file or a sync: matching
 * each to an entry or product, and adding it once.
 */
class Imports extends Component
{
    /** @var array<string, ElementInterface|false> */
    private array $matches = [];

    public function read(string $contents, ?string $format = null): ImportResult
    {
        return (new Importer)->read($contents, $format);
    }

    /**
     * The formats a file can be read as, by key.
     *
     * @return array<string, string>
     */
    public function formats(): array
    {
        $formats = [];

        foreach (Formats::all() as $format) {
            $formats[$format->key()] = $format->label();
        }

        return $formats;
    }

    /**
     * A platform's name, for where a review came from.
     */
    public function sourceLabel(string $key): string
    {
        return Sources::find($key)?->label() ?? $this->formats()[$key] ?? ucfirst($key);
    }

    /**
     * What an import would do, without adding anything: how many reviews
     * match an entry or product, are already here, or match nothing.
     *
     * @param  iterable<ImportedReview>  $reviews
     * @return array{matched: int, existing: int, unmatched: array<int, string>, unpublished: int, examples: array<int, array{review: ImportedReview, element: ElementInterface|null}>}
     */
    public function preview(iterable $reviews, ImportOptions $options): array
    {
        $preview = ['matched' => 0, 'existing' => 0, 'unmatched' => [], 'unpublished' => 0, 'examples' => []];

        foreach ($reviews as $imported) {
            if (! $imported->published && ! $options->includeUnpublished) {
                $preview['unpublished']++;

                continue;
            }

            if (Review::find()->fingerprint($this->fingerprint($imported, $options))->status(null)->trashed(null)->exists()) {
                $preview['existing']++;

                continue;
            }

            $element = $this->match($imported, $options);

            if ($element) {
                $preview['matched']++;
            } else {
                $preview['unmatched'][] = $this->describe($imported);
            }

            if (count($preview['examples']) < 5) {
                $preview['examples'][] = ['review' => $imported, 'element' => $element];
            }
        }

        $preview['unmatched'] = array_values(array_unique($preview['unmatched']));

        return $preview;
    }

    /**
     * @param  iterable<ImportedReview>  $reviews
     */
    public function import(iterable $reviews, ImportOptions $options, ?ImportReport $report = null): ImportReport
    {
        $report ??= new ImportReport;
        $changed = [];

        foreach ($reviews as $imported) {
            try {
                $result = $this->add($imported, $options);
            } catch (Throwable $e) {
                Craft::error('Reviews couldn’t import a review: '.$e->getMessage(), 'reviews');
                $report->failed++;

                continue;
            }

            if ($result === null) {
                $report->unmatched[] = $this->describe($imported);

                continue;
            }

            [$state, $elementId] = $result;

            match ($state) {
                'added' => $report->added++,
                'updated' => $report->updated++,
                'skipped' => null,
                default => $report->unchanged++,
            };

            if ($state === 'added' || $state === 'updated') {
                $changed[$elementId] = true;
            }
        }

        // Each element's numbers and pages once, rather than per review.
        foreach (array_keys($changed) as $elementId) {
            Plugin::getInstance()->reviews->changed($elementId);
        }

        return $report;
    }

    /**
     * @return array{string, int}|null The state ("added", "updated", "unchanged" or "skipped") and the element ID, or null when nothing matched.
     */
    protected function add(ImportedReview $imported, ImportOptions $options): ?array
    {
        if (! $imported->published && ! $options->includeUnpublished && ! $options->update) {
            return ['skipped', 0];
        }

        $fingerprint = $this->fingerprint($imported, $options);
        $existing = Review::find()->fingerprint($fingerprint)->status(null)->trashed(null)->one();

        if ($existing) {
            if (! $options->update || $existing->trashed) {
                return ['unchanged', (int) $existing->elementId];
            }

            return [$this->update($existing, $imported, $options) ? 'updated' : 'unchanged', (int) $existing->elementId];
        }

        if (! $imported->published && ! $options->includeUnpublished) {
            return ['skipped', 0];
        }

        $element = $this->match($imported, $options);

        if (! $element) {
            return null;
        }

        $review = new Review;
        $review->elementId = (int) $element->id;
        $review->reviewSiteId = $options->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $review->fingerprint = $fingerprint;
        $review->source = $imported->source;
        $review->sourceId = $options->sourceId;
        $review->counted = $options->counted;
        $review->email = $imported->email;
        $this->fillPhotos($review, $imported);
        $review->dateCreated = $imported->date ? DateTime::createFromImmutable($imported->date) : null;
        $this->fill($review, $imported, $options);

        if (! Plugin::getInstance()->reviews->save($review, false)) {
            throw new \RuntimeException(implode(' ', $review->getFirstErrors()));
        }

        return ['added', (int) $element->id];
    }

    /**
     * What tells a review apart: the platform's own, and for a sync, which
     * source it came from too, so two shops on the same platform (each
     * with a review number 1) are never mixed up.
     */
    protected function fingerprint(ImportedReview $imported, ImportOptions $options): string
    {
        $fingerprint = $imported->fingerprint();

        return $options->sourceId ? hash('sha256', $fingerprint.'|source:'.$options->sourceId) : $fingerprint;
    }

    protected function update(Review $review, ImportedReview $imported, ImportOptions $options): bool
    {
        $snapshot = fn () => [
            $review->rating, $review->title, $review->body, $review->name, $review->email, $review->verified,
            $review->reply, $review->dateReplied?->format(DATE_ATOM), $review->sourceUrl, $review->photos,
            $review->reviewStatus, $review->counted,
        ];

        $before = $snapshot();
        $review->counted = $options->counted;
        $review->email = $imported->email;
        $this->fillPhotos($review, $imported);
        $this->fill($review, $imported, $options);

        if ($before === $snapshot()) {
            return false;
        }

        return Plugin::getInstance()->reviews->save($review, false);
    }

    protected function fill(Review $review, ImportedReview $imported, ImportOptions $options): void
    {
        $review->rating = $imported->rating;
        $review->title = $imported->title !== null ? mb_substr($imported->title, 0, 255) : null;
        $review->body = $imported->body;
        $review->name = mb_substr($imported->name, 0, 255);
        $review->verified = $imported->verified;
        $review->reply = $imported->reply;
        $review->dateReplied = $imported->reply !== null && $imported->replyDate ? DateTime::createFromImmutable($imported->replyDate) : null;
        $url = Values::url($imported->url);
        $review->sourceUrl = $url !== null && mb_strlen($url) <= 500 ? $url : null;

        // A new review follows the platform. Later, a sync only takes down
        // what the platform took down: an editor's decision stands.
        if (! $review->id) {
            $review->reviewStatus = match (true) {
                ! $imported->published => ReviewStatus::Rejected,
                $options->status === 'pending' => ReviewStatus::Pending,
                default => ReviewStatus::Approved,
            };
            $review->dateApproved = $review->isApproved() ? ($imported->date ? DateTime::createFromImmutable($imported->date) : new DateTime) : null;
        } elseif (! $imported->published) {
            $review->reviewStatus = ReviewStatus::Rejected;
            $review->dateApproved = null;
        }
    }

    /**
     * An imported review's photos are the platform's addresses. Photos an
     * editor added here (as assets) stay.
     */
    protected function fillPhotos(Review $review, ImportedReview $imported): void
    {
        $own = array_values(array_filter($review->photos, fn ($photo) => ! isset($photo['url'])));
        $review->photos = [...$own, ...array_map(fn (string $url) => ['url' => $url], array_slice(Values::urls($imported->photos), 0, 10))];
    }

    /**
     * The entry or product an imported review is of: by ID (a Reviews
     * export), SKU (Commerce), handle (an entry's or product's slug), page
     * address, or exact title. Reviews of the business go on the element
     * chosen for them.
     */
    public function match(ImportedReview $imported, ImportOptions $options): ?ElementInterface
    {
        if ($imported->isAboutTheBusiness()) {
            return $options->businessElementId ? Craft::$app->getElements()->getElementById($options->businessElementId) : null;
        }

        $key = json_encode($imported->product).'|'.$options->siteId;

        if (! array_key_exists($key, $this->matches)) {
            $this->matches[$key] = $this->find($imported->product, $options->siteId) ?? false;
        }

        return $this->matches[$key] ?: null;
    }

    /**
     * @param  array<string, string>  $product
     */
    protected function find(array $product, ?int $siteId): ?ElementInterface
    {
        $settings = Plugin::getInstance()->getSettings();
        $sections = $settings->sections ?: null;

        if (isset($product['id']) && ctype_digit($product['id'])) {
            $element = Craft::$app->getElements()->getElementById((int) $product['id'], null, $siteId);

            if ($element instanceof Entry || Commerce::isProduct($element)) {
                return $element;
            }
        }

        if (isset($product['sku']) && Commerce::installed()) {
            $variant = Variant::find()->sku($product['sku'])->status(null)->siteId($siteId)->one();

            if ($variant?->getOwner()) {
                return $variant->getOwner();
            }
        }

        if (isset($product['handle'])) {
            if (Commerce::installed()) {
                $found = Product::find()->slug($product['handle'])->status(null)->siteId($siteId)->one();

                if ($found) {
                    return $found;
                }
            }

            $found = Entry::find()->slug($product['handle'])->section($sections)->status(null)->siteId($siteId)->one();

            if ($found) {
                return $found;
            }
        }

        if (isset($product['url'])) {
            $path = trim((string) parse_url($product['url'], PHP_URL_PATH), '/');

            if ($path !== '') {
                $found = Craft::$app->getElements()->getElementByUri($path, $siteId, false);

                if ($found) {
                    return $found;
                }

                // A shop's address is often /products/{handle}.
                $slug = basename($path);
                $found = Entry::find()->slug($slug)->section($sections)->status(null)->siteId($siteId)->one();

                if ($found) {
                    return $found;
                }
            }
        }

        if (isset($product['name'])) {
            return Entry::find()->title($product['name'])->section($sections)->status(null)->siteId($siteId)->one();
        }

        return null;
    }

    protected function describe(ImportedReview $imported): string
    {
        $product = $imported->product;

        if ($product === []) {
            return Craft::t('reviews', 'A review of the business (choose where those go)');
        }

        $parts = [];

        foreach ($product as $key => $value) {
            $parts[] = $key.' '.$value;
        }

        return implode(', ', $parts);
    }
}
