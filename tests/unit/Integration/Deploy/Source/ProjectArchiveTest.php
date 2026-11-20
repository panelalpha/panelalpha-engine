<?php

namespace Tests\Integration\Deploy\Source;

use App\Lib\Deploy\Source\ProjectArchive;
use App\System\Privileged\AccountFiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\ProjectArchiveFixtures;

class ProjectArchiveTest extends TestCase
{
    use ProjectArchiveFixtures;

    public function test_lists_the_top_level_as_the_account(): void
    {
        $argv = ProjectArchive::topLevelArgv(new AccountFiles(1004, 1005), self::DIR);

        $this->assertSame([LocalRoot::CLIENT, 'account.listEntryTypes', '--', '1004', '1005', self::DIR], $argv);
        $this->assertSame(['find', self::DIR, '-mindepth', '1', '-maxdepth', '1', '-printf', '%y %f\0'], LocalRoot::plain($argv));
    }
}
