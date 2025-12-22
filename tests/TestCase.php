<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        config(['logging.default' => 'null']);
        config(['logging.channels.single.driver' => 'null']);
        config(['logging.channels.daily.driver' => 'null']);
        config(['logging.channels.emergency.path' => null]);
    }
}
