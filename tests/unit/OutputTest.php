<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\web\View;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\fields\ReviewsField;

class OutputTest extends TestCase
{
    public function test_schema_has_the_rating_and_only_reviews_that_count(): void
    {
        $entry = $this->entry('books', 'Schema Book');
        $this->review($entry, 5, ReviewStatus::Approved, ['title' => 'Mine']);
        $this->review($entry, 1, ReviewStatus::Approved, ['title' => 'Elsewhere', 'source' => 'trustpilot', 'counted' => false]);

        $schema = $this->plugin()->reviews->schema($entry);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Schema Book', $schema['name']);
        $this->assertSame(1, $schema['aggregateRating']['reviewCount']);
        $this->assertSame(5.0, (float) $schema['aggregateRating']['ratingValue']);
        $this->assertCount(1, $schema['review']);
        $this->assertSame('Jo B.', $schema['review'][0]['author']['name']);
    }

    public function test_no_reviews_no_schema(): void
    {
        $this->assertNull($this->plugin()->reviews->schema($this->entry()));
        $this->assertSame('', (string) $this->render('{{ craft.reviews.schema(entry) }}', ['entry' => $this->entry()]));
        $this->assertSame('', (string) $this->render('{{ craft.reviews.schemaProperties(entry) }}', ['entry' => $this->entry()]));
    }

    public function test_the_ready_made_block_renders(): void
    {
        $entry = $this->entry();
        $this->review($entry, 4, ReviewStatus::Approved, ['title' => 'Charming', 'reply' => 'Thanks!']);
        $this->review($entry, 2, ReviewStatus::Pending, ['title' => 'Hidden one']);
        $this->review($entry, 3, ReviewStatus::Approved, ['title' => 'From afar', 'source' => 'judgeme', 'counted' => false, 'sourceUrl' => 'https://judge.me/r/1']);

        $html = $this->render('{{ craft.reviews.display(entry) }}', ['entry' => $entry]);

        $this->assertStringContainsString('class="reviews-ui"', $html);
        $this->assertStringContainsString('Charming', $html);
        $this->assertStringContainsString('Thanks!', $html);
        $this->assertStringNotContainsString('Hidden one', $html);
        $this->assertStringContainsString('From Judge.me', $html);
        $this->assertStringContainsString('name="elementId" value="'.$entry->id.'"', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('From 1 review', $html);
    }

    public function test_the_form_can_be_included_on_its_own_by_element_id(): void
    {
        $entry = $this->entry();
        $html = $this->render("{% include '_reviews/form' with { elementId: id } only %}", ['id' => $entry->id]);

        $this->assertStringContainsString('name="elementId" value="'.$entry->id.'"', $html);
        $this->assertStringContainsString('class="reviews-ui"', $html);
    }

    public function test_criteria_averages_are_rounded(): void
    {
        $this->settings(['criteria' => [['handle' => 'story', 'label' => 'Story']]]);
        $entry = $this->entry();

        foreach ([4, 4, 3] as $story) {
            $this->review($entry, 4, ReviewStatus::Approved, ['ratings' => ['story' => $story]]);
        }

        $html = $this->render('{{ craft.reviews.summaryHtml(entry) }}', ['entry' => $entry]);

        $this->assertStringContainsString('<dd>3.7</dd>', $html);
    }

    public function test_the_styles_come_from_the_core(): void
    {
        $this->assertStringContainsString('@layer reviews', (string) $this->render('{{ craft.reviews.styles(true) }}'));
    }

    public function test_a_reviews_field_switches_an_entry_off(): void
    {
        $field = new ReviewsField(['name' => 'Reviews', 'handle' => 'reviews']);
        $value = $field->normalizeValue(['enabled' => false], null);

        $this->assertFalse($value->enabled);
        $this->assertTrue($field->normalizeValue(null, null)->enabled);
        $this->assertSame(['enabled' => false], $field->serializeValue($value, null));
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function render(string $twig, array $variables = []): string
    {
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        return $view->renderString($twig, $variables);
    }
}
