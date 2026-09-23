<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindBuildStorage;
use PHPUnit\Framework\TestCase;

class DindBuildStorageTest extends TestCase
{
    public function test_detects_disk_full_errors_from_common_build_outputs(): void
    {
        $this->assertTrue(DindBuildStorage::isDiskFullError('ERR_PNPM_ENOSPC while fetching packages'));
        $this->assertTrue(DindBuildStorage::isDiskFullError('errstr: database or disk is full'));
        $this->assertTrue(DindBuildStorage::isDiskFullError('ENOSPC: no space left on device'));
        $this->assertFalse(DindBuildStorage::isDiskFullError('network timeout talking to registry'));
    }

    /**
     * A failed log write is only ENOSPC when DeployLogger says so. Ownership
     * failures produce the same sentence and must not read as a full disk.
     */
    public function test_a_failed_deploy_log_write_counts_only_with_the_enospc_hint(): void
    {
        $path = '/var/www/html/storage/logs/deploy/shopware/latest.json.tmp';

        $this->assertFalse(DindBuildStorage::isDiskFullError(
            'Could not write deploy log file: ' . $path
        ));
        $this->assertTrue(DindBuildStorage::isDiskFullError(
            'Could not write deploy log file: ' . $path . ': no space left on device'
        ));
    }

    public function test_parses_available_bytes_from_df_pk(): void
    {
        $df = <<<TXT
Filesystem     1024-blocks     Used Available Capacity Mounted on
/dev/sda1         39000000 33000000   4200000      89% /home/plane/docker
TXT;

        $this->assertSame(4200000 * 1024, DindBuildStorage::parseDfAvailableBytes($df));
    }

    public function test_parses_total_buildx_cache_bytes(): void
    {
        $output = <<<TXT
ID   RECLAIMABLE   SIZE
abc  false         1.2GB
Reclaimable: 0B
Total:      6.036GB
TXT;

        $this->assertSame(6481105650, DindBuildStorage::parseBuildxTotalBytes($output));
    }

    public function test_reclaims_when_space_is_low_and_no_inner_containers(): void
    {
        $this->assertTrue(DindBuildStorage::shouldReclaimBeforeBuild(
            2 * 1024 * 1024 * 1024,
            6 * 1024 * 1024 * 1024,
            false
        ));

        $this->assertTrue(DindBuildStorage::shouldReclaimBeforeBuild(
            7 * 1024 * 1024 * 1024,
            2 * 1024 * 1024 * 1024,
            false
        ));
    }

    public function test_does_not_reclaim_when_containers_exist_or_space_is_fine(): void
    {
        $this->assertFalse(DindBuildStorage::shouldReclaimBeforeBuild(
            7 * 1024 * 1024 * 1024,
            512 * 1024 * 1024,
            false
        ));

        $this->assertFalse(DindBuildStorage::shouldReclaimBeforeBuild(
            2 * 1024 * 1024 * 1024,
            6 * 1024 * 1024 * 1024,
            true
        ));
    }

    public function test_partial_reclaim_script_restarts_inner_docker(): void
    {
        $script = DindBuildStorage::partialReclaimScript();

        $this->assertStringContainsString('rm -rf "$ROOT/buildkit"', $script);
        $this->assertStringContainsString('supervisorctl', $script);
        $this->assertStringContainsString('start docker', $script);
    }

    /**
     * StorageReclaim runs both scripts with `sh -lc`, which is dash in the
     * account image: a bash array failed every heavy build with
     * `sh: 3: Syntax error: "(" unexpected`.
     */
    public function test_reclaim_scripts_parse_under_posix_sh(): void
    {
        foreach ([DindBuildStorage::partialReclaimScript(), DindBuildStorage::fullWipeScript()] as $script) {
            $process = proc_open(['sh', '-n'], [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $script);
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $stderr);
        }
    }

    public function test_full_wipe_script_removes_entire_data_root_without_restart(): void
    {
        $script = DindBuildStorage::fullWipeScript();

        $this->assertStringContainsString('rm -rf "$ROOT"', $script);
        $this->assertStringNotContainsString('service docker start', $script);
        $this->assertStringNotContainsString('/buildkit"', $script);
    }
}
