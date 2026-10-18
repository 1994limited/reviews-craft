<?php

namespace nineteenninetyfour\reviews\tests\unit;

use Codeception\Test\Unit;
use UnitTester;

abstract class TestCase extends Unit
{
    use Helpers;

    protected UnitTester $tester;

    protected function _before(): void
    {
        parent::_before();

        $this->setUpReviews();
    }
}
