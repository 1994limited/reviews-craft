<?php

namespace nineteenninetyfour\reviews\services;

use RuntimeException;

/**
 * Someone can't review this at all: the message says why, for the visitor.
 */
class ReviewException extends RuntimeException {}
