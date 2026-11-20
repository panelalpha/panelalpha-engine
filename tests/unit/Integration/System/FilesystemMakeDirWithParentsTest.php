<?php

namespace Tests\Integration\System;

use App\System\Filesystem;
use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\FilesystemMakeDirWithParentsFixtures;

class FilesystemMakeDirWithParentsTest extends TestCase
{
    use FilesystemMakeDirWithParentsFixtures;

    public function test_creates_missing_parents_top_down(): void
    {
        $system = $this->system(['/', '/srv']);

        (new Filesystem($system))->makeDirWithParents('/srv/a/b', '1000:1000');

        $this->assertSame([
            Privileged::files()->makeDirectory('/srv/a'),
            Privileged::files()->changeOwner('1000:1000', '/srv/a'),
            Privileged::files()->makeDirectory('/srv/a/b'),
            Privileged::files()->changeOwner('1000:1000', '/srv/a/b'),
        ], $system->execs);
    }

    /** Every directory probe fails, as with a missing or refusing root broker. */
    public function test_throws_instead_of_looping_when_root_is_not_a_directory(): void
    {
        $system = $this->system([]);

        try {
            (new Filesystem($system))->makeDirWithParents('/srv/a/b');
            $this->fail('Expected makeDirWithParents() to throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("'/' does not read as a directory", $e->getMessage());
        }

        $this->assertSame(['/srv/a/b', '/srv/a', '/srv', '/'], $system->probes);
        $this->assertSame([], $system->execs);
    }
}
