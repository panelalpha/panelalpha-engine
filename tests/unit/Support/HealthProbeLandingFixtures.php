<?php

namespace Tests\Support;

trait HealthProbeLandingFixtures
{
    /** @var list<array{0: resource, 1: string}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$process, $dir]) {
            proc_terminate($process);
            proc_close($process);
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
        $this->servers = [];
        parent::tearDown();
    }
}
