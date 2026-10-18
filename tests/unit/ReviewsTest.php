<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;

class ReviewsTest extends TestCase
{
    public function test_a_review_saves_and_loads_everything_it_says(): void
    {
        $entry = $this->entry();
        $review = $this->review($entry, 4, ReviewStatus::Pending, ['ratings' => ['value' => 3], 'title' => 'Good 😀', 'photos' => [['url' => 'https://example.com/a.jpg']]]);

        $loaded = Review::find()->id($review->id)->status(null)->one();

        $this->assertSame(4, $loaded->rating);
        $this->assertSame(['value' => 3], $loaded->ratings);
        $this->assertSame('Good 😀', $loaded->title);
        $this->assertSame(ReviewStatus::Pending, $loaded->reviewStatus);
        $this->assertSame([['url' => 'https://example.com/a.jpg']], $loaded->photos);
        $this->assertSame($entry->id, $loaded->getElement()?->id);
        $this->assertSame('Jo B.', $loaded->getDisplayName());
    }

    public function test_queries_find_only_approved_reviews_unless_asked(): void
    {
        $entry = $this->entry();
        $this->review($entry, 5);
        $this->review($entry, 1, ReviewStatus::Pending);
        $this->review($entry, 2, ReviewStatus::Spam);

        $this->assertSame(1, (int) Review::find()->elementId($entry->id)->count());
        $this->assertSame(3, (int) Review::find()->elementId($entry->id)->status(null)->count());
        $this->assertSame(1, (int) Review::find()->elementId($entry->id)->status('pending')->count());
        $this->assertSame(1, (int) Review::find()->status(null)->rating('<= 1')->count());
    }

    public function test_the_summary_counts_approved_reviews_that_count(): void
    {
        $entry = $this->entry();
        $this->review($entry, 5);
        $this->review($entry, 4);
        $this->review($entry, 1, ReviewStatus::Pending);
        $this->review($entry, 1, ReviewStatus::Approved, ['source' => 'trustpilot', 'counted' => false]);

        $summary = $this->plugin()->reviews->summary($entry);

        $this->assertSame(2, $summary->count());
        $this->assertSame(4.5, $summary->average());
    }

    public function test_the_summary_is_cached_until_a_review_changes(): void
    {
        $entry = $this->entry();
        $this->review($entry, 5);
        $pending = $this->review($entry, 1, ReviewStatus::Pending);

        $this->assertSame(1, $this->plugin()->reviews->summary($entry)->count());

        $this->plugin()->reviews->approve($pending);

        $summary = $this->plugin()->reviews->summary($entry);
        $this->assertSame(2, $summary->count());
        $this->assertSame(3.0, $summary->average());
        $this->assertNotNull($pending->dateApproved);
    }

    public function test_criteria_are_averaged(): void
    {
        $this->settings(['criteria' => [['handle' => 'value', 'label' => 'Value for money'], ['handle' => 'Bad Handle!', 'label' => 'x']]]);
        $entry = $this->entry();
        $this->review($entry, 5, ReviewStatus::Approved, ['ratings' => ['value' => 4]]);
        $this->review($entry, 3, ReviewStatus::Approved, ['ratings' => ['value' => 2]]);

        $this->assertSame([['handle' => 'value', 'label' => 'Value for money']], $this->plugin()->reviews->criteria());
        $this->assertSame(3.0, $this->plugin()->reviews->summary($entry)->criterionAverage('value'));
    }

    public function test_only_live_entries_in_reviewed_sections_take_reviews(): void
    {
        $this->settings(['sections' => ['books']]);

        $this->assertTrue($this->plugin()->reviews->reviewable($this->entry('books')));
        $this->assertFalse($this->plugin()->reviews->reviewable($this->entry('journal')));
        $this->assertFalse($this->plugin()->reviews->reviewable($this->entry('books', 'Draft', [], ['enabled' => false])));
        $this->assertFalse($this->plugin()->reviews->reviewable(null));
    }

    public function test_replying_sets_and_clears_the_reply(): void
    {
        $review = $this->review($this->entry());
        $editor = $this->user('Ed Itor');

        $this->plugin()->reviews->reply($review, '  Thank you!  ', $editor);
        $loaded = Review::find()->id($review->id)->one();
        $this->assertSame('Thank you!', $loaded->reply);
        $this->assertSame($editor->id, $loaded->replyById);
        $this->assertNotNull($loaded->dateReplied);

        $this->plugin()->reviews->reply($loaded, '');
        $this->assertNull(Review::find()->id($review->id)->one()->reply);
    }

    public function test_a_vote_counts_once_per_voter(): void
    {
        $review = $this->review($this->entry());

        $this->assertTrue($this->plugin()->reviews->vote($review, 'voter-a'));
        $this->assertFalse($this->plugin()->reviews->vote($review, 'voter-a'));
        $this->assertTrue($this->plugin()->reviews->vote($review, 'voter-b'));
        $this->assertSame(2, $review->helpful);

        // Saving a review loaded before the votes keeps the votes' count.
        $stale = Review::find()->id($review->id)->one();
        $stale->helpful = 0;
        $this->plugin()->reviews->save($stale);
        $this->assertSame(2, Review::find()->id($review->id)->one()->helpful);
    }

    public function test_pending_reviews_get_no_votes(): void
    {
        $review = $this->review($this->entry(), 5, ReviewStatus::Pending);

        $this->assertFalse($this->plugin()->reviews->vote($review, 'voter-a'));
    }

    public function test_forgetting_someone_keeps_their_words_but_not_their_name(): void
    {
        $entry = $this->entry();
        $review = $this->review($entry, 5, ReviewStatus::Approved, ['email' => 'jo@example.com', 'name' => 'Jo Bloggs']);
        $other = $this->review($entry, 4, ReviewStatus::Approved, ['email' => 'sam@example.com']);

        $this->assertSame(1, $this->plugin()->reviews->forget('JO@example.com'));

        $loaded = Review::find()->id($review->id)->one();
        $this->assertSame('Anonymous', $loaded->name);
        $this->assertNull($loaded->email);
        $this->assertSame('A good read.', $loaded->body);
        $this->assertSame('sam@example.com', Review::find()->id($other->id)->one()->email);

        $this->assertSame(1, $this->plugin()->reviews->forget('sam@example.com', null, true));
        $this->assertNull(Review::find()->id($other->id)->status(null)->one());
    }

    public function test_deleting_an_entry_deletes_its_reviews_for_good(): void
    {
        $entry = $this->entry();
        $review = $this->review($entry);

        Craft::$app->getElements()->deleteElement($entry, true);

        $this->assertNull(Review::find()->id($review->id)->status(null)->trashed(null)->one());
    }
}
