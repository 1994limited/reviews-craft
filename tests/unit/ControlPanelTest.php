<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\web\TemplateResponseBehavior;
use craft\web\View;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\models\Source;
use nineteenninetyfour\reviews\widgets\PendingReviews;

/**
 * Every control panel page renders.
 */
class ControlPanelTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        $this->commerceStore();
        $this->plugin()->controllerNamespace = 'nineteenninetyfour\\reviews\\controllers';
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $this->actingAs();
    }

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCEPT']);
        $this->plugin()->controllerNamespace = 'nineteenninetyfour\\reviews\\console\\controllers';

        parent::_after();
    }

    protected function page(string $route, array $params = []): string
    {
        $response = $this->action($route, $params, 'GET', json: false);
        $this->assertNotNull($response);

        // Craft renders a template response as it's sent: render it here.
        $behavior = $response->getBehavior(TemplateResponseBehavior::NAME);

        if ($behavior) {
            return Craft::$app->getView()->renderTemplate($behavior->template, $behavior->variables, View::TEMPLATE_MODE_CP);
        }

        return (string) $response->content;
    }

    public function test_a_review_page_shows_what_it_says(): void
    {
        $review = $this->review($this->entry(), 4, ReviewStatus::Pending, ['title' => 'Lovely read', 'reply' => 'Thank you', 'photos' => [['url' => 'https://example.com/a.jpg']]]);

        $html = $this->page('reviews/moderate/edit', ['reviewId' => $review->id]);

        $this->assertStringContainsString('Lovely read', $html);
        $this->assertStringContainsString('★★★★☆', $html);
        $this->assertStringContainsString('Thank you', $html);
        $this->assertStringContainsString('reviews/moderate/approve', $html);
    }

    public function test_the_settings_page_renders(): void
    {
        $html = $this->page('reviews/settings/edit');

        $this->assertStringContainsString('settings[sections]', $html);
        $this->assertStringContainsString('settings[captcha]', $html);
    }

    public function test_the_import_page_renders(): void
    {
        $this->assertStringContainsString('reviews/import/preview', $this->page('reviews/import/index'));
    }

    public function test_the_sync_pages_render(): void
    {
        $source = new Source(['type' => 'feefo', 'name' => 'Feefo', 'settings' => ['merchant' => 'dog-ear']]);
        $this->plugin()->sync->save($source);

        $this->assertStringContainsString('Feefo', $this->page('reviews/sources/index'));
        // The platform's own fields (autosuggest inputs, named in their props).
        $this->assertStringContainsString('Merchant identifier', $this->page('reviews/sources/edit', ['sourceId' => $source->id]));
        $this->assertStringContainsString('Business unit ID', $this->page('reviews/sources/edit', ['type' => 'trustpilot']));
    }

    public function test_the_widget_lists_what_s_waiting(): void
    {
        $this->review($this->entry(), 2, ReviewStatus::Pending, ['title' => 'Needs a look']);

        $this->assertStringContainsString('Needs a look', (string) (new PendingReviews)->getBodyHtml());
    }
}
