<?php

namespace Tests\Integration\System\Project;

use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;

class VolumeArchiveVolumeScriptTest extends TestCase
{
    public function test_restore_script_is_ash_compatible(): void
    {
        $script = VolumeArchive::volumeRestoreScript();

        $this->assertStringNotContainsString('shopt', $script);
        $this->assertStringContainsString('.panelalpha-aside', $script);
        $this->assertStringContainsString('leftover .panelalpha-aside exists', $script);

        $tmp = tempnam(sys_get_temp_dir(), 'pa-vol-restore-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_the_replace_script_leaves_the_volume_holding_the_archive_alone(): void
    {
        $root = sys_get_temp_dir().'/pa-vol-replace-'.bin2hex(random_bytes(4));
        mkdir("{$root}/source/sub", 0777, true);
        mkdir("{$root}/data/old", 0777, true);
        file_put_contents("{$root}/source/sub/new.txt", 'new');
        file_put_contents("{$root}/source/.env", 'kept');
        file_put_contents("{$root}/data/old/stale.txt", 'stale');
        file_put_contents("{$root}/data/.hidden", 'stale');
        file_put_contents("{$root}/data/-n", 'stale');
        exec('tar -czf '.escapeshellarg("{$root}/archive").' -C '.escapeshellarg("{$root}/source").' .', $output, $packed);
        file_put_contents("{$root}/replace.sh", str_replace(['/data', '/archive'], ["{$root}/data", "{$root}/archive"], VolumeArchive::volumeReplaceScript()));

        exec('sh '.escapeshellarg("{$root}/replace.sh").' 2>&1', $output, $exitCode);
        $left = array_values(array_diff(scandir("{$root}/data") ?: [], ['.', '..']));
        $new = (string) @file_get_contents("{$root}/data/sub/new.txt");
        exec('rm -rf '.escapeshellarg($root));

        $this->assertSame([0, 0], [$packed, $exitCode], implode("\n", $output));
        $this->assertSame(['.env', 'sub'], $left);
        $this->assertSame('new', $new);
    }

    public function test_rollback_script_is_ash_compatible(): void
    {
        $script = VolumeArchive::volumeRollbackScript();

        $this->assertStringNotContainsString('shopt', $script);
        $this->assertStringContainsString('.panelalpha-aside', $script);

        $tmp = tempnam(sys_get_temp_dir(), 'pa-vol-rollback-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec('sh -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
        unlink($tmp);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
