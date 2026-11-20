<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\Runtime\JavaRuntime;

trait JavaRuntimeFixtures
{
    private JavaRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new JavaRuntime();
    }

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }
}
