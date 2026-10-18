<?php

namespace nineteenninetyfour\reviews\services;

use RuntimeException;

/**
 * A guest sent a review of something their email has reviewed already. It
 * isn't kept, and they're thanked as for a new one, so the form can't be
 * used to find out whether an address has written a review.
 */
class DuplicateReview extends RuntimeException {}
