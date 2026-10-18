<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use craft\models\Site;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\variables\ReviewsVariable;

class SitesTest extends TestCase
{
    protected Site $primary;

    protected Site $second;

    protected function _before(): void
    {
        parent::_before();

        $this->commerceStore();
        $sites = Craft::$app->getSites();
        $this->primary = $sites->getPrimarySite();
        $this->second = $sites->getSiteByHandle('second') ?? new Site([
            'groupId' => $this->primary->groupId,
            'name' => 'Second',
            'handle' => 'second',
            'language' => 'fr',
            'hasUrls' => true,
            'baseUrl' => 'http://second.test/',
        ]);

        if (! $this->second->id) {
            $this->assertTrue($sites->saveSite($this->second), json_encode($this->second->getErrors()));
        }
    }

    protected function _after(): void
    {
        Craft::$app->getSites()->setCurrentSite($this->primary);

        parent::_after();
    }

    public function test_shared_by_default_every_site_sees_every_review(): void
    {
        $entry = $this->entry();
        $this->review($entry, 5);
        $this->review($entry, 1, ReviewStatus::Approved, ['reviewSiteId' => $this->second->id]);

        Craft::$app->getSites()->setCurrentSite($this->second);

        $this->assertSame(2, $this->plugin()->reviews->summary($entry)->count());
        $this->assertSame(2, (int) (new ReviewsVariable)->reviews($entry)->count());
    }

    public function test_separate_sites_each_have_their_own_reviews_and_average(): void
    {
        $this->settings(['sites' => 'separate']);
        $entry = $this->entry();
        $this->review($entry, 5, ReviewStatus::Approved, ['email' => 'same@example.com']);
        $this->review($entry, 1, ReviewStatus::Approved, ['reviewSiteId' => $this->second->id]);

        $variable = new ReviewsVariable;

        $this->assertSame(5.0, $this->plugin()->reviews->summary($entry)->average());
        $this->assertSame(1, (int) $variable->reviews($entry)->count());
        $this->assertNotNull($this->plugin()->reviews->existing($entry->id, null, 'same@example.com'));

        Craft::$app->getSites()->setCurrentSite($this->second);

        $this->assertSame(1.0, $this->plugin()->reviews->summary($entry)->average());
        $this->assertSame(1, (int) $variable->reviews($entry)->count());
        $this->assertSame(1, $this->plugin()->reviews->schema($entry)['aggregateRating']['reviewCount']);

        // Someone who reviewed it on the first site can review it here too.
        $this->assertNull($this->plugin()->reviews->existing($entry->id, null, 'same@example.com'));
    }
}
