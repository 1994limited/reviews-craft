<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Craft;
use NineteenNinetyFour\Reviews\Core\Spam\TimeToken;
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\elements\ReviewStatus;
use nineteenninetyfour\reviews\services\Spam;
use yii\web\ForbiddenHttpException;

class SubmitTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->enableCsrfValidation = false;
        $this->plugin()->controllerNamespace = 'nineteenninetyfour\\reviews\\controllers';
    }

    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCEPT']);
        $this->plugin()->controllerNamespace = 'nineteenninetyfour\\reviews\\console\\controllers';

        parent::_after();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function form(int $elementId, array $extra = []): array
    {
        return [
            'elementId' => $elementId,
            'rating' => 4,
            'title' => 'Lovely',
            'body' => 'A good read.',
            'name' => 'Jo Bloggs',
            'email' => 'jo@example.com',
            Spam::TIME_FIELD => (new TimeToken((string) Craft::$app->getConfig()->getGeneral()->securityKey))->issue(time() - 60),
            ...$extra,
        ];
    }

    public function test_a_guest_s_review_waits_for_an_editor(): void
    {
        $entry = $this->entry();

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id)));

        $this->assertTrue($data['success']);
        $review = Review::find()->elementId($entry->id)->status(null)->one();
        $this->assertSame(ReviewStatus::Pending, $review->reviewStatus);
        $this->assertSame('jo@example.com', $review->email);
        $this->assertSame(4, $review->rating);
        $this->assertFalse($review->verified);
        $this->assertSame(0, $this->plugin()->reviews->summary($entry)->count());
    }

    public function test_what_s_wrong_is_said_field_by_field(): void
    {
        $entry = $this->entry();

        $response = $this->action('reviews/reviews/submit', body: $this->form($entry->id, ['rating' => '', 'email' => 'not-an-email', 'body' => '']));
        $data = $this->json($response);

        $this->assertFalse($data['success']);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('rating', $data['errors']);
        $this->assertArrayHasKey('email', $data['errors']);
        $this->assertArrayHasKey('body', $data['errors']);
        $this->assertSame(0, (int) Review::find()->status(null)->count());
    }

    public function test_too_many_links_is_refused(): void
    {
        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($this->entry()->id, ['body' => 'https://a.example https://b.example https://c.example'])));

        $this->assertArrayHasKey('body', $data['errors']);
    }

    public function test_a_bot_is_thanked_and_nothing_is_kept(): void
    {
        $entry = $this->entry();

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, [Spam::HONEYPOT => 'https://spam.example'])));
        $this->assertTrue($data['success']);

        $this->settings(['minimumSeconds' => 5]);
        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, [Spam::TIME_FIELD => 'made-up'])));
        $this->assertTrue($data['success']);

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, [Spam::OPENED_FIELD => '1'])));
        $this->assertTrue($data['success']);

        $this->assertSame(0, (int) Review::find()->status(null)->count());
    }

    public function test_a_guest_s_second_review_is_thanked_but_not_kept(): void
    {
        $entry = $this->entry();
        $this->action('reviews/reviews/submit', body: $this->form($entry->id));

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, ['email' => 'JO@example.com', 'body' => 'Changed my mind.'])));

        $this->assertTrue($data['success']);
        $this->assertSame(1, (int) Review::find()->status(null)->count());
        $this->assertSame('A good read.', Review::find()->status(null)->one()->body);
    }

    public function test_entries_that_dont_take_reviews_are_refused(): void
    {
        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($this->entry('books', 'Off', [], ['enabled' => false])->id)));

        $this->assertFalse($data['success']);
        $this->assertSame(0, (int) Review::find()->status(null)->count());
    }

    public function test_users_only_needs_a_signed_in_reviewer(): void
    {
        $this->settings(['who' => 'users']);
        $entry = $this->entry();

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id)));
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Sign in', $data['errors']['rating'][0]);

        $user = $this->actingAs([]);
        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, ['name' => 'Someone Else', 'email' => ''])));
        $this->assertTrue($data['success']);

        $review = Review::find()->status(null)->one();
        $this->assertSame($user->id, $review->userId);
        $this->assertSame('Ada Lovelace', $review->name);
        $this->assertSame($user->email, $review->email);
    }

    public function test_a_signed_in_reviewer_changes_their_review_and_it_waits_again(): void
    {
        $this->settings(['autoApprove' => 'never']);
        $entry = $this->entry();
        $this->actingAs([]);

        $this->action('reviews/reviews/submit', body: $this->form($entry->id));
        $review = Review::find()->status(null)->one();
        $this->plugin()->reviews->approve($review);

        $this->action('reviews/reviews/submit', body: $this->form($entry->id, ['rating' => 2, 'body' => 'On second thoughts.']));

        $this->assertSame(1, (int) Review::find()->status(null)->count());
        $changed = Review::find()->status(null)->one();
        $this->assertSame(2, $changed->rating);
        $this->assertSame(ReviewStatus::Pending, $changed->reviewStatus);
    }

    public function test_signed_in_reviews_can_go_up_straight_away(): void
    {
        $this->settings(['autoApprove' => 'users']);
        $entry = $this->entry();
        $this->actingAs([]);

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, ['rating' => 1])));

        $this->assertSame('approved', $data['review']['status']);
        $this->assertSame(1, $this->plugin()->reviews->summary($entry)->count());
    }

    public function test_criteria_ratings_are_kept_and_checked(): void
    {
        $this->settings(['criteria' => [['handle' => 'value', 'label' => 'Value']]]);
        $entry = $this->entry();

        $data = $this->json($this->action('reviews/reviews/submit', body: $this->form($entry->id, ['ratings' => ['value' => 9]])));
        $this->assertArrayHasKey('ratings.value', $data['errors']);

        $this->action('reviews/reviews/submit', body: $this->form($entry->id, ['ratings' => ['value' => '3', 'other' => 5]]));
        $this->assertSame(['value' => 3], Review::find()->status(null)->one()->ratings);
    }

    public function test_helpful_votes_are_once_per_guest_by_cookie(): void
    {
        $review = $this->review($this->entry());

        $data = $this->json($this->action('reviews/reviews/vote', body: ['reviewId' => $review->id]));
        $this->assertSame(1, $data['helpful']);

        $cookie = Craft::$app->getResponse()->getCookies()->get('reviews_voter');
        $this->assertNotNull($cookie);

        Craft::$app->getRequest()->getCookies()->readOnly = false;
        Craft::$app->getRequest()->getCookies()->add($cookie);

        $data = $this->json($this->action('reviews/reviews/vote', body: ['reviewId' => $review->id]));
        $this->assertSame(1, $data['helpful']);
        $this->assertStringContainsString('already', $data['message']);
    }

    public function test_a_reviewer_can_delete_only_their_own_review(): void
    {
        $entry = $this->entry();
        $user = $this->actingAs([]);
        $mine = $this->review($entry, 5, ReviewStatus::Approved, ['userId' => $user->id]);
        $theirs = $this->review($entry, 5);

        $this->action('reviews/reviews/delete', body: ['reviewId' => $theirs->id]);
        $this->assertNotNull(Review::find()->id($theirs->id)->one());

        $this->action('reviews/reviews/delete', body: ['reviewId' => $mine->id]);
        $this->assertNull(Review::find()->id($mine->id)->status(null)->one());
    }

    public function test_moderating_needs_permission(): void
    {
        $review = $this->review($this->entry(), 5, ReviewStatus::Pending);
        $this->actingAs([]);

        $this->expectException(ForbiddenHttpException::class);
        $this->action('reviews/moderate/approve', body: ['reviewId' => $review->id]);
    }

    public function test_moderators_approve_reject_and_reply(): void
    {
        $review = $this->review($this->entry(), 5, ReviewStatus::Pending);
        $this->actingAs(['reviews-moderate']);

        $this->assertSame('approved', $this->json($this->action('reviews/moderate/approve', body: ['reviewId' => $review->id]))['status']);
        $this->assertSame('rejected', $this->json($this->action('reviews/moderate/reject', body: ['reviewId' => $review->id, 'note' => 'Off topic']))['status']);
        $this->assertSame('Off topic', Review::find()->id($review->id)->status(null)->one()->note);

        $this->action('reviews/moderate/reply', body: ['reviewId' => $review->id, 'reply' => 'Thanks']);
        $this->assertSame('Thanks', Review::find()->id($review->id)->status(null)->one()->reply);
    }
}
