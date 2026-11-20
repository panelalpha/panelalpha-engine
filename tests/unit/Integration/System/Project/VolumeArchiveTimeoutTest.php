<?php

namespace Tests\Integration\System\Project;

use App\Support\JobBudget;
use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;
use Tests\Support\VolumeArchiveTimeoutFixtures;

/**
 * The caller decides a volume archive's timeout: a forward step passes the job's
 * remaining window, a rollback the cleanup reserve.
 */
class VolumeArchiveTimeoutTest extends TestCase
{
    use VolumeArchiveTimeoutFixtures;

    public function test_a_given_timeout_caps_the_volume_tar(): void
    {
        $timeouts = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts)), 120);

        $archive->tarVolume('proj_dbdata', '/tmp/volume-dbdata.tar.gz');

        $this->assertSame([120], $timeouts);
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
}
