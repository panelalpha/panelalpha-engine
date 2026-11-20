<?php

namespace Tests\Support;

trait SystemPackagesFixtures
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($dir . '/' . $entry);
                }
            }
            rmdir($dir);
        }
        $this->dirs = [];

        parent::tearDown();
    }
}
