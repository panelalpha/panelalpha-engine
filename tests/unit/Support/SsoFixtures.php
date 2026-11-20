<?php

namespace Tests\Support;

use Mockery;

trait SsoFixtures
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
