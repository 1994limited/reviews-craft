# Events and PHP

## Events

On the `reviews` service, each with a `ReviewEvent` whose `review` is the review:

| Event | |
| --- | --- |
| `Reviews::EVENT_AFTER_SUBMIT` | A visitor sent a review (new or changed). `$event->review->submitting` is true. |
| `Reviews::EVENT_AFTER_APPROVE` | |
| `Reviews::EVENT_AFTER_REJECT` | Rejected or marked as spam. |
| `Reviews::EVENT_AFTER_REPLY` | The site's reply was added or changed. |

```php
use nineteenninetyfour\reviews\events\ReviewEvent;
use nineteenninetyfour\reviews\services\Reviews;
use yii\base\Event;

Event::on(Reviews::class, Reviews::EVENT_AFTER_SUBMIT, function (ReviewEvent $event) {
    if ($event->review->rating <= 2) {
        // Tell customer service.
    }
});
```

Reviews are elements, so Craft's element events (`Element::EVENT_AFTER_SAVE` and so on) fire too.

## In PHP

```php
use nineteenninetyfour\reviews\elements\Review;
use nineteenninetyfour\reviews\Plugin;

$reviews = Plugin::getInstance()->reviews;

$summary = $reviews->summary($entry);           // average(), count(), breakdown()…
$latest = Review::find()->elementId($entry->id)->limit(3)->all();

$reviews->approve($review);
$reviews->reply($review, 'Thank you!', $user);
$reviews->reject($review, 'Not about this book.');
```

Change reviews through the `reviews` service rather than saving them directly: it keeps the numbers, photos and caches right.
