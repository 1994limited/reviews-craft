<?php

namespace nineteenninetyfour\reviews\events;

use nineteenninetyfour\reviews\elements\Review;
use yii\base\Event;

class ReviewEvent extends Event
{
    public Review $review;
}
