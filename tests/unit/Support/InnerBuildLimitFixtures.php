<?php

namespace Tests\Support;

trait InnerBuildLimitFixtures
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<resource> */
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            proc_terminate($child, 9);
            proc_close($child);
        }
        foreach (array_reverse($this->dirs) as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }
}
