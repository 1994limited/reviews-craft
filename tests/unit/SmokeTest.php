<?php

namespace nineteenninetyfour\reviews\tests\unit;

use nineteenninetyfour\reviews\elements\Review;

class SmokeTest extends TestCase
{
    public function test_the_plugin_is_installed(): void
    {
        $this->assertNotNull($this->plugin());
        $this->assertSame(0, (int) Review::find()->status(null)->count());
    }
}
