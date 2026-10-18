<?php

namespace nineteenninetyfour\reviews\tests\unit;

use craft\db\Query;
use NineteenNinetyFour\Reviews\Core\Sync\SyncException;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\models\ImportOptions;
use nineteenninetyfour\reviews\models\Source;

class ImportTest extends TestCase
{
    protected function yotpo(string ...$rows): string
    {
        return "Review ID,Review Creation Date,Review Status,Review Score,Review Title,Review Content,Reviewer Display Name,Reviewer Email,Reviewer Type,Product Handle,Product SKU,Comment Content\n".implode("\n", $rows)."\n";
    }

    public function test_a_yotpo_export_is_matched_by_handle_and_added_once(): void
    {
        $entry = $this->entry('books', 'The Lighthouse Keeper', [], ['slug' => 'the-lighthouse-keeper']);
        $file = $this->yotpo(
            '101,2026-03-01,Published,5,Wonderful,Couldn’t put it down,Ann Lee,ann@example.com,verified_buyer,the-lighthouse-keeper,LH-1,Thank you Ann',
            '102,2026-03-02,Published,4,Good,Solid,Ben,,anonymous_user,no-such-book,NO-1,',
            '103,2026-03-03,Deleted,1,Spam,Buy pills,Bot,,anonymous_user,the-lighthouse-keeper,LH-1,',
        );

        $imports = $this->plugin()->imports;
        $result = $imports->read($file);
        $this->assertSame('yotpo', $result->format?->key());

        $preview = $imports->preview($result->reviews, new ImportOptions);
        $this->assertSame(1, $preview['matched']);
        $this->assertSame(1, $preview['unpublished']);
        $this->assertSame(['sku NO-1, handle no-such-book'], $preview['unmatched']);
        $this->assertSame(0, (int) Review::find()->status(null)->count());

        $report = $imports->import($result->reviews, new ImportOptions);
        $this->assertSame(1, $report->added);

        $review = Review::find()->elementId($entry->id)->one();
        $this->assertSame('yotpo', $review->source);
        $this->assertSame('Thank you Ann', $review->reply);
        $this->assertTrue($review->verified);
        $this->assertFalse($review->counted);
        $this->assertSame('2026-03-01', $review->dateCreated->format('Y-m-d'));

        // Not counted: the average leaves it out.
        $this->assertSame(0, $this->plugin()->reviews->summary($entry)->count());

        // The same file again adds nothing.
        $again = $imports->import($imports->read($file)->reviews, new ImportOptions);
        $this->assertSame(0, $again->added);
        $this->assertSame(1, $again->unchanged);
    }

    public function test_reviews_counted_as_your_own_count_towards_the_rating(): void
    {
        $entry = $this->entry('books', 'Counted', [], ['slug' => 'counted']);
        $imports = $this->plugin()->imports;

        $imports->import($imports->read($this->yotpo('201,2026-03-01,Published,4,Fine,Fine,Cy,,verified_buyer,counted,,'))->reviews, new ImportOptions(['counted' => true, 'status' => 'pending']));

        $review = Review::find()->status(null)->one();
        $this->assertSame(ReviewStatus::Pending, $review->reviewStatus);

        $this->plugin()->reviews->approve($review);
        $this->assertSame(1, $this->plugin()->reviews->summary($entry)->count());
    }

    public function test_reviews_of_the_business_go_where_they_re_told_or_nowhere(): void
    {
        $page = $this->entry('journal', 'What customers say');
        $file = $this->yotpo('301,2026-03-01,Published,5,Great shop,Fast,Di,,verified_buyer,,yotpo_site_reviews,');
        $imports = $this->plugin()->imports;

        $this->assertSame(1, count($imports->import($imports->read($file)->reviews, new ImportOptions)->unmatched));
        $this->assertSame(1, $imports->import($imports->read($file)->reviews, new ImportOptions(['businessElementId' => $page->id]))->added);
        $this->assertSame($page->id, Review::find()->one()->elementId);
    }

    public function test_our_own_export_imports_with_titles_and_ids(): void
    {
        $entry = $this->entry('books', 'Moved Book');
        $csv = "id,entry,entry_title,rating,title,body,name,email,status,verified,created_at\n"
            ."abc,999999,Moved Book,5,Yes,Brilliant,Eve,eve@example.com,approved,1,2026-01-05T10:00:00+00:00\n";

        $report = $this->plugin()->imports->import($this->plugin()->imports->read($csv)->reviews, new ImportOptions(['counted' => true]));

        $this->assertSame(1, $report->added);
        $this->assertSame($entry->id, Review::find()->one()->elementId);
    }

    public function test_a_sync_adds_then_updates_and_takes_down_what_the_platform_took_down(): void
    {
        $entry = $this->entry('books', 'Synced', [], ['slug' => 'synced']);
        $client = new FakeClient([
            ['reviews' => [['id' => 1, 'rating' => 5, 'product_handle' => 'synced', 'body' => 'Good', 'reviewer' => ['name' => 'Fi']]]],
        ]);
        $this->plugin()->sync->client = $client;

        $source = new Source(['type' => 'judgeme', 'name' => 'Judge.me', 'settings' => ['shop_domain' => 'x.myshopify.com', 'api_token' => 'secret-token']]);
        $this->assertTrue($this->plugin()->sync->save($source), json_encode($source->getErrors()));

        // The token is stored encrypted.
        $row = (new Query)->from('{{%reviews_sources}}')->where(['id' => $source->id])->one();
        $this->assertStringNotContainsString('secret-token', (string) $row['settings']);
        $this->assertSame('secret-token', $this->plugin()->sync->find($source->id)->settings['api_token']);

        $report = $this->plugin()->sync->run($this->plugin()->sync->find($source->id));
        $this->assertSame(1, $report->added);

        $review = Review::find()->elementId($entry->id)->one();
        $this->assertSame($source->id, $review->sourceId);
        $this->assertFalse($review->counted);
        $this->assertNotNull($this->plugin()->sync->find($source->id)->lastSynced);

        // The platform hides it: so does the sync.
        $client->replies = [['reviews' => [['id' => 1, 'rating' => 5, 'product_handle' => 'synced', 'body' => 'Good', 'reviewer' => ['name' => 'Fi'], 'hidden' => true]]]];
        $report = $this->plugin()->sync->run($this->plugin()->sync->find($source->id));
        $this->assertSame(1, $report->updated);
        $this->assertSame(ReviewStatus::Rejected, Review::find()->id($review->id)->status(null)->one()->reviewStatus);
    }

    public function test_a_failed_sync_is_recorded(): void
    {
        $this->plugin()->sync->client = new FakeClient([null]);
        $source = new Source(['type' => 'feefo', 'name' => 'Feefo', 'settings' => ['merchant' => 'dog-ear']]);
        $this->plugin()->sync->save($source);

        try {
            $this->plugin()->sync->run($source);
            $this->fail('The sync should have failed.');
        } catch (SyncException) {
        }

        $this->assertNotNull($this->plugin()->sync->find($source->id)->lastError);
    }

    public function test_only_platforms_that_allow_it_can_count(): void
    {
        $trustpilot = new Source(['type' => 'trustpilot', 'name' => 'TP', 'countInSummary' => true, 'settings' => ['business_unit' => 'b', 'api_key' => 'k', 'profile_url' => 'https://uk.trustpilot.com/review/x']]);
        $this->assertFalse($this->plugin()->sync->save($trustpilot));
        $this->assertArrayHasKey('countInSummary', $trustpilot->getErrors());

        $feefo = new Source(['type' => 'feefo', 'name' => 'Feefo', 'countInSummary' => true, 'settings' => ['merchant' => 'x']]);
        $this->assertTrue($this->plugin()->sync->save($feefo));
        $this->assertTrue($feefo->counts());
    }
}
