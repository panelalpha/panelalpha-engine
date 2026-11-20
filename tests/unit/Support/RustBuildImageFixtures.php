<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;

trait RustBuildImageFixtures
{
    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        RuntimeImageCatalog::flush();
    }

    protected function tearDown(): void
    {
        RuntimeImageCatalog::useConfig(null);
        RuntimeImageCatalog::flush();
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];

        parent::tearDown();
    }
}
