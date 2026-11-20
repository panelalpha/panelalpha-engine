<?php

namespace Tests\Unit\System\Project;

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

    public function test_a_given_timeout_caps_the_volume_rollback(): void
    {
        $timeouts = [];
        $archive = new VolumeArchive($this->standInDind($this->recordingSystem($timeouts)), JobBudget::CLEANUP_SECONDS);

        $archive->rollbackVolume('proj_dbdata');

        $this->assertSame([JobBudget::CLEANUP_SECONDS], $timeouts);
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
}
