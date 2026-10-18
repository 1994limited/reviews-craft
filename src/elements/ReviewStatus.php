<?php

namespace nineteenninetyfour\reviews\elements;

/**
 * Where a review is in moderation.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';
}
