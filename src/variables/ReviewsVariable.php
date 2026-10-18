<?php

namespace nineteenninetyfour\reviews\variables;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\web\View;
use NineteenNinetyFour\Reviews\Core\Rating\Stars;
use NineteenNinetyFour\Reviews\Core\Rating\Summary;
use NineteenNinetyFour\Reviews\Core\Schema\SchemaBuilder;
use nineteenninetyfour\reviews\elements\db\ReviewQuery;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\models\ReviewForm;
use nineteenninetyfour\reviews\Plugin;
use nineteenninetyfour\reviews\services\Submissions;
use Twig\Markup;

/**
 * `craft.reviews` in templates.
 */
class ReviewsVariable
{
    /**
     * Approved reviews: of an element, or with any criteria.
     *
     * {% set reviews = craft.reviews.reviews(entry).rating(5).limit(3).all() %}
     *
     * @param  array<string, mixed>  $criteria
     * @return ReviewQuery<int, Review>
     */
    public function reviews(ElementInterface|array|null $element = null, array $criteria = []): ReviewQuery
    {
        if (is_array($element)) {
            [$criteria, $element] = [$element, null];
        }

        $query = Review::find();
        Craft::configure($query, $criteria);

        if ($element) {
            $query->elementId($element->id);
        }

        return $query;
    }

    /**
     * An element's numbers: average, count, breakdown, criteria.
     */
    public function summary(ElementInterface $element): Summary
    {
        return Plugin::getInstance()->reviews->summary($element);
    }

    /**
     * Stars to draw for a rating: full, half or empty.
     *
     * @return array<int, array{type: string, full: bool, half: bool, empty: bool, number: int}>
     */
    public function stars(float|int|null $rating): array
    {
        return Stars::for($rating);
    }

    public function canReview(ElementInterface $element): bool
    {
        return $this->refusal($element) === null;
    }

    /**
     * Why the visitor can't review an element: "unavailable", "sign_in" or
     * "buyers_only"; null when they can.
     */
    public function refusal(ElementInterface $element): ?string
    {
        return Plugin::getInstance()->submissions->refusal($element, Craft::$app->getUser()->getIdentity(), Submissions::sessionOrders());
    }

    public function refusalMessage(string $reason): string
    {
        return Plugin::getInstance()->submissions->refusalMessage($reason);
    }

    /**
     * The signed-in visitor's own review of an element, to change it.
     */
    public function mine(ElementInterface $element): ?Review
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user ? Plugin::getInstance()->reviews->existing((int) $element->id, $user) : null;
    }

    /**
     * A platform's name, for where an imported review came from.
     */
    public function sourceLabel(?string $key): string
    {
        return $key === null ? Craft::$app->getSites()->getCurrentSite()->getName() : Plugin::getInstance()->imports->sourceLabel($key);
    }

    /**
     * The import formats, by key.
     *
     * @return array<string, string>
     */
    public function importFormats(): array
    {
        return Plugin::getInstance()->imports->formats();
    }

    /**
     * @return array<int, array{handle: string, label: string}>
     */
    public function criteria(): array
    {
        return Plugin::getInstance()->reviews->criteria();
    }

    /**
     * Everything a review form needs, for writing your own.
     *
     * @return array<string, mixed>
     */
    public function formOptions(ElementInterface $element, ?ReviewForm $form = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $user = Craft::$app->getUser()->getIdentity();
        $mine = $this->mine($element);
        $refusal = $this->refusal($element);

        $existing = [];

        foreach ($mine->photos ?? [] as $index => $photo) {
            $existing[] = ['index' => $index, 'url' => isset($photo['asset']) ? (Asset::find()->id($photo['asset'])->one()?->getUrl()) : null];
        }

        $old = $form ? [
            'rating' => $form->rating,
            'ratings' => $form->ratings,
            'title' => $form->title,
            'body' => $form->body,
            'name' => $form->name,
            'email' => $form->email,
        ] : [
            'rating' => $mine?->rating,
            'ratings' => $mine->ratings ?? [],
            'title' => $mine?->title,
            'body' => $mine?->body,
            'name' => null,
            'email' => null,
        ];

        return [
            'element' => $element,
            'refusal' => $refusal,
            'refusalMessage' => $refusal ? $this->refusalMessage($refusal) : null,
            'review' => $mine,
            'editing' => $mine !== null,
            'errors' => $form?->getErrors() ?? [],
            'old' => $old,
            'criteria' => $this->criteria(),
            'needsName' => $user === null,
            'titleRequired' => $settings->requireTitle,
            'bodyRequired' => $settings->requireBody,
            'titleLimit' => $settings->titleLimit,
            'bodyLimit' => $settings->bodyLimit,
            'nameLimit' => $settings->nameLimit,
            'photosEnabled' => $plugin->photos->enabled(),
            'maxPhotos' => $plugin->photos->max(),
            'existingPhotos' => $existing,
            'moderated' => $settings->autoApprove === 'never' || ($settings->autoApprove === 'users' && ! $user),
        ];
    }

    /**
     * The hidden fields, captcha and script every review form needs.
     */
    public function formFields(ElementInterface $element): Markup
    {
        $spam = Plugin::getInstance()->spam;
        $captcha = $spam->captcha();

        return Template::raw(
            Html::actionInput('reviews/reviews/submit')
            .Html::csrfInput()
            .Html::hiddenInput('elementId', (string) $element->id)
            .$spam->hiddenFields()
            .($captcha ? $captcha->widget() : '')
            .$spam->script()
        );
    }

    /**
     * The ready-made block: the average, the reviews and the form.
     *
     * {{ craft.reviews.display(entry) }}
     *
     * @param  array<string, mixed>  $options
     */
    public function display(ElementInterface $element, array $options = []): Markup
    {
        return $this->render('_reviews/display', $element, $options);
    }

    /** @param  array<string, mixed>  $options */
    public function summaryHtml(ElementInterface $element, array $options = []): Markup
    {
        return $this->render('_reviews/summary', $element, $options + ['wrap' => true]);
    }

    /** @param  array<string, mixed>  $options */
    public function list(ElementInterface $element, array $options = []): Markup
    {
        return $this->render('_reviews/list', $element, $options + ['wrap' => true]);
    }

    /** @param  array<string, mixed>  $options */
    public function form(ElementInterface $element, array $options = []): Markup
    {
        return $this->render('_reviews/form', $element, $options + ['wrap' => true]);
    }

    /**
     * The default styles: a <link> to the stylesheet, or with `inline`, a
     * <style> block. They sit in a cascade layer, so a site's own CSS wins.
     */
    public function styles(bool $inline = false): Markup
    {
        $path = Craft::getAlias('@vendor/1994/reviews-core/resources/css');

        if ($inline || ! is_dir($path)) {
            $file = is_dir($path) ? $path.'/reviews.css' : dirname(__DIR__, 2).'/vendor/1994/reviews-core/resources/css/reviews.css';

            return Template::raw('<style>'.(is_file($file) ? file_get_contents($file) : '').'</style>');
        }

        $url = Craft::$app->getAssetManager()->getPublishedUrl($path, true, 'reviews.css');

        return Template::raw(Html::cssFile($url));
    }

    /**
     * The JSON-LD for an element, as a <script>.
     */
    public function schema(ElementInterface $element, ?string $type = null): Markup
    {
        return Template::raw(SchemaBuilder::script(Plugin::getInstance()->reviews->schema($element, $type)));
    }

    /**
     * The rating and reviews alone, to put in JSON-LD you write yourself.
     *
     * @return array<string, mixed>|null
     */
    public function schemaData(ElementInterface $element): ?array
    {
        return Plugin::getInstance()->reviews->schemaParts($element);
    }

    /**
     * The same, as JSON properties followed by a comma, to go straight
     * after the "{" of JSON-LD written by hand or by an SEO plugin.
     */
    public function schemaProperties(ElementInterface $element): Markup
    {
        return Template::raw(SchemaBuilder::properties(Plugin::getInstance()->reviews->schemaParts($element)));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function render(string $template, ElementInterface $element, array $options): Markup
    {
        $view = Craft::$app->getView();
        $form = Craft::$app->getUrlManager()->getRouteParams()['reviewForm'] ?? null;

        $html = $view->renderTemplate($template, [
            'element' => $element,
            'options' => $options,
            'heading' => $options['heading'] ?? Craft::t('reviews', 'Reviews'),
            'perPage' => (int) ($options['perPage'] ?? Plugin::getInstance()->getSettings()->perPage),
            'showForm' => $options['form'] ?? true,
            'wrap' => $options['wrap'] ?? false,
            'reviewForm' => $form instanceof ReviewForm ? $form : null,
            'votesEnabled' => Plugin::getInstance()->getSettings()->votesEnabled,
        ], View::TEMPLATE_MODE_SITE);

        return Template::raw($html);
    }
}
