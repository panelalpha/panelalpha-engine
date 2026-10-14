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
 * next deploy. Root extracts into a fresh directory closed to the account, and
 * hands it over only once tar is done.
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

        $this->assertSame(['rm', '-rf', '--', '/home/alice/project.incoming'], $system->ran[0]);
        $this->assertSame(['mkdir', '-m', '700', '--', '/home/alice/project.incoming'], $system->ran[1]);
        $this->assertSame('tar', $system->ran[2][0]);
        $this->assertSame(['chmod', '755', '/home/alice/project.incoming'], $system->ran[3]);
        $this->assertSame(['chown', '1002:1003', '/home/alice/project.incoming'], $system->ran[4]);
        $this->assertCount(5, $system->ran);
    }
}
