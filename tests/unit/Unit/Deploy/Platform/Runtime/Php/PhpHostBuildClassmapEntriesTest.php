<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;

/**
 * A classmap entry that is not a path is ignored rather than created as a directory.
 */
class PhpHostBuildClassmapEntriesTest extends TestCase
{
    public function test_entries_that_are_not_strings_are_ignored(): void
    {
        $this->assertSame(
            PhpHostBuild::classmapDirsStep('{"autoload":{"classmap":["legacy"]}}'),
            PhpHostBuild::classmapDirsStep('{"autoload":{"classmap":[5, null, {"a":"b"}, "legacy"]}}')
        );
        $this->assertStringContainsString("'legacy'", PhpHostBuild::classmapDirsStep('{"autoload":{"classmap":["legacy"]}}'));
    }
}
