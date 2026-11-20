<?php

namespace Tests\Unit\System;

use App\System\Filesystem;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\TestCase;
use Tests\Support\FilesystemMakeDirWithParentsFixtures;

class FilesystemMakeDirWithParentsTest extends TestCase
{
    use FilesystemMakeDirWithParentsFixtures;

    public function test_a_relative_path_is_refused_before_anything_runs(): void
    {
        $system = $this->system([]);

        try {
            (new Filesystem($system))->makeDirWithParents('a/b');
            $this->fail('Expected makeDirWithParents() to refuse a relative path');
        } catch (InvalidArgument $e) {
            $this->assertSame('Refusing path "a/b" for a root command', $e->getMessage());
        }

        $this->assertSame([], $system->probes);
        $this->assertSame([], $system->execs);
    }
}
