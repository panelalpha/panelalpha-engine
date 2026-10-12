<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup;
use PHPUnit\Framework\TestCase;

/**
 * A restore extracted into a directory root had just created, so the swapped-in
 * ~/project was root-owned and the account could not write into it until the
 * next deploy.
 */
class BackupRestoreOwnershipTest extends TestCase
{
    public function test_the_incoming_tree_is_handed_to_the_account_once_extracted(): void
    {
        $system = new class () extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = (array) $cmd;

                return '';
            }
        };

        $user = new User();
        $user->username = 'alice';
        $user->details = ['UID' => 1002, 'GID' => 1003];
        $backup = new class (new ProjectAggregate($system, $user), new BackupRecord()) extends Backup {
            public function extractForTest(string $archivePath, string $incomingDir): void
            {
                $this->extractProjectArchive($archivePath, $incomingDir);
            }
        };

        $backup->extractForTest('/tmp/files.tar.gz', 'project.incoming');

        $this->assertSame('mkdir', $system->ran[0][0]);
        $this->assertSame('tar', $system->ran[1][0]);
        $this->assertSame(['chown', '1002:1003', '/home/alice/project.incoming'], $system->ran[2]);
        $this->assertCount(3, $system->ran);
    }
}
