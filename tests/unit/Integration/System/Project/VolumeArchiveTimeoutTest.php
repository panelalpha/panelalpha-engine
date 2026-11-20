<?php

namespace Tests\Integration\System\Project;

use App\Support\JobBudget;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;

/**
 * The caller decides a volume archive's timeout: a forward step passes the job's
 * remaining window, a rollback the cleanup reserve.
 */
class VolumeArchiveTimeoutTest extends TestCase
{
    public function test_a_given_timeout_caps_the_volume_tar(): void
    {
        $timeouts = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts)), 120);

        $archive->tarVolume('proj_dbdata', '/tmp/volume-dbdata.tar.gz');

        $this->assertSame([120], $timeouts);
    }

    public function test_a_given_timeout_caps_the_volume_rollback(): void
    {
        $timeouts = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts)), JobBudget::CLEANUP_SECONDS);

        $archive->rollbackVolume('proj_dbdata');

        $this->assertSame([JobBudget::CLEANUP_SECONDS], $timeouts);
    }

    public function test_without_a_timeout_a_volume_tar_uses_the_single_command_window(): void
    {
        $timeouts = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts)));

        $archive->tarVolume('proj_dbdata', '/tmp/volume-dbdata.tar.gz');

        $this->assertSame([JobBudget::COMMAND_SECONDS], $timeouts);
    }

    /** The account's daemon runs the tar image itself, with the volume read-only and the scratch directory to write to. */
    public function test_the_tar_runs_in_the_accounts_daemon_on_the_volume_read_only(): void
    {
        $timeouts = [];
        $asked = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts, $asked)));

        $archive->tarVolume('proj_dbdata', '/tmp/volume-dbdata.tar.gz');

        $this->assertSame([['account-docker.run', [
            'test', 'alpine:3.20', '2',
            'proj_dbdata', '/data', 'ro',
            '/home/test/.panelalpha-backup-scratch', '/out', 'rw',
            'tar', '-C', '/data', '-czf', '/out/volume-dbdata.tar.gz', '.',
        ]]], $asked);
    }

    public function test_a_restore_mounts_the_archive_read_only_and_the_volume_writable(): void
    {
        $timeouts = [];
        $asked = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts, $asked)));

        $archive->restoreVolume('proj_dbdata', '/tmp/volume-dbdata.tar.gz');

        $this->assertSame([['account-docker.run', [
            'test', 'alpine:3.20', '2',
            'proj_dbdata', '/data', 'rw',
            '/home/test/.panelalpha-backup-scratch/volume-dbdata.tar.gz', '/archive', 'ro',
            'sh', '-c', VolumeArchive::volumeRestoreScript(),
        ]]], $asked);
    }

    public function test_a_replace_mounts_the_archive_in_the_home_read_only_and_the_volume_writable(): void
    {
        $timeouts = [];
        $asked = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts, $asked)));

        $archive->replaceVolume('proj_dbdata', '/home/test/.panelalpha-volumes/proj_dbdata/_data.incoming');

        $this->assertSame([['account-docker.run', [
            'test', 'alpine:3.20', '2',
            'proj_dbdata', '/data', 'rw',
            '/home/test/.panelalpha-volumes/proj_dbdata/_data.incoming', '/archive', 'ro',
            'sh', '-c', VolumeArchive::volumeReplaceScript(),
        ]]], $asked);
        $this->assertSame([JobBudget::COMMAND_SECONDS], $timeouts);
    }

    /** @param list<array{0: string, 1: list<string>}> $asked */
    private function recordingSystem(array &$timeouts, array &$asked = []): System
    {
        return new class ($timeouts, $asked) extends System {
            public function __construct(private array &$timeouts, private array &$asked) {}

            public function engineDirPath(): string
            {
                return sys_get_temp_dir();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                if (LocalRoot::isRequest($argv) && ($argv[1] ?? '') === '--host') {
                    return $this->execOnHost($argv, $env, $timeout);
                }
                $this->timeouts[] = $timeout;
                if (LocalRoot::isRequest($argv)) {
                    $this->asked[] = [(string) LocalRoot::action($argv), LocalRoot::values($argv)];
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['php', '-r', '']);
            }
        };
    }

    private function standInDind(System $system): Dind
    {
        return new class ($system) extends Dind {
            public function __construct(private System $host) {}

            public function system(): System
            {
                return $this->host;
            }

            public function username(): string
            {
                return 'test';
            }

            public function homeDirPath(): string
            {
                return '/home/test';
            }

            public function composeFilePath(): string
            {
                return '/home/test/docker-compose.yml';
            }
        };
    }
}
