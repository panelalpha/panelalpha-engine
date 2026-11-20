<?php

namespace Tests\Support;

trait WebserverFixtures
{
    private static ?string $engineDir = null;

    protected function tearDown(): void
    {
        if (self::$engineDir !== null) {
            rmdir(self::$engineDir);
            self::$engineDir = null;
        }
        parent::tearDown();
    }
}
