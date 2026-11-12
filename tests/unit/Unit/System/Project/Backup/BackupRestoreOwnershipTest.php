<?php

namespace Tests\Unit\System\Project\Backup;

use App\Models\User;
use App\Support\JobDeadline;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\ProjectFiles;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;

/**
 * A restore extracted into a directory root had just created, so the swapped-in
 * ~/project was root-owned and the account could not write into it until the
 * next deploy. Root extracts beside the homes, where no account container
 * reaches, hands the tree over, and renames it into the home once tar is done.
 */
class BackupRestoreOwnershipTest extends TestCase
{
    public function test_the_tree_is_extracted_outside_the_home_and_renamed_in_once_handed_over(): void
    {
        $system = $this->system();

        $this->files($system)->extract('/tmp/files.tar.gz', 'project.incoming');

        $stage = '/home/.panelalpha-restore-stage/alice';
        $this->assertSame(['rm', '-rf', '--', '/home/alice/project.incoming', $stage], $system->ran[0]);
        $this->assertSame(['mkdir', '-p', '-m', '700', '--', '/home/.panelalpha-restore-stage'], $system->ran[1]);
        $this->assertSame(['mkdir', '-m', '700', '--', $stage], $system->ran[2]);
        $this->assertSame(['tar', '-xzf', '/tmp/files.tar.gz', '-C', $stage, '--strip-components=1'], $system->ran[3]);
        $this->assertSame(['chmod', '--', '755', $stage], $system->ran[4]);
        $this->assertSame(['chown', '--', '1002:1003', $stage], $system->ran[5]);
        $this->assertSame(['mv', '-T', '--', $stage, '/home/alice/project.incoming'], $system->ran[6]);
        $this->assertCount(7, $system->ran);
    }

    public function test_a_failed_extraction_leaves_nothing_in_the_stage_or_the_home(): void
    {
        $system = $this->system();
        $system->failing = 'tar';

        try {
            $this->files($system)->extract('/tmp/files.tar.gz', 'project.incoming');
            $this->fail('the extraction should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('tar failed', $e->getMessage());
        }

        $this->assertSame(['rm', '-rf', '--', '/home/.panelalpha-restore-stage/alice'], end($system->ran));
        $this->assertNotContains('mv', array_column($system->ran, 0));
    }

    private function system(): System
    {
        return new class () extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public ?string $failing = null;

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;

                return array_slice(array_values($argv), 0, 2) === [LocalRoot::CLIENT, '--host']
                    ? $this->execOnHost($argv, $env, $timeout)
                    : parent::exec($cmd, $env, $timeout);
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) HostCommand::argv($cmd);
                $this->ran[] = $argv;
                if ($this->failing === $argv[0]) {
                    throw new \RuntimeException($argv[0].' failed');
                }

                return '';
            }
        };
    }

    private function files(System $system): ProjectFiles
    {
        $user = new User();
        $user->username = 'alice';
        $user->details = ['UID' => 1002, 'GID' => 1003];

        return new ProjectFiles(new ProjectAggregate($system, $user), JobDeadline::startingNow());
    }
}
