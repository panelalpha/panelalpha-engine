<?php

namespace Tests\Integration\Support;

use App\Support\QueueWorkers;
use Tests\TestCase;

/**
 * Applying a worker count: written to the engine's env file and the scan directory, s6-svscan
 * told when it runs, and retired workers whose supervisor is gone cleared away.
 */
class QueueWorkersApplyTest extends TestCase
{
    private string $base;

    private string $scanDir;

    private string $shimDir;

    private string|false $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/pa-queue-apply-'.bin2hex(random_bytes(4));
        $this->scanDir = $this->base.'/service';
        $this->shimDir = $this->base.'/bin';
        mkdir($this->scanDir, 0755, true);
        mkdir($this->shimDir);
        file_put_contents($this->base.'/.env-core', "APP_KEY=base64:x\n# QUEUE_WORKERS=\n");
        $this->app->useEnvironmentPath($this->base);
        $this->app->loadEnvironmentFrom('.env-core');
        $this->path = getenv('PATH');
        putenv('PATH='.$this->shimDir.':'.$this->path);
        $this->shim('s6-svscanctl', 'exit 0');
        $this->shim('s6-svok', 'exit 1');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->path);
        exec('rm -rf '.escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_the_configured_count_is_read_from_the_env_file(): void
    {
        file_put_contents($this->base.'/.env-core', "APP_KEY=base64:x\nQUEUE_WORKERS=5\n");

        $this->assertSame(5, QueueWorkers::configured());
    }

    public function test_a_count_that_is_not_a_number_falls_back_to_the_hosts_default(): void
    {
        file_put_contents($this->base.'/.env-core', "APP_KEY=base64:x\nQUEUE_WORKERS=many\n");

        $this->assertSame(
            QueueWorkers::defaultFor((string) @file_get_contents('/proc/meminfo')),
            QueueWorkers::configured()
        );
    }

    public function test_a_count_out_of_range_changes_nothing(): void
    {
        try {
            QueueWorkers::apply(QueueWorkers::MAX + 1, $this->scanDir);
            $this->fail('a count above the maximum must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Enter a number from 1 to 32.', $e->getMessage());
        }

        $this->assertSame([], glob($this->scanDir.'/*') ?: []);
        $this->assertSame("APP_KEY=base64:x\n# QUEUE_WORKERS=\n", file_get_contents($this->base.'/.env-core'));
    }

    public function test_applying_without_s6_writes_the_count_and_the_services_and_tells_nobody(): void
    {
        QueueWorkers::apply(2, $this->scanDir);

        $this->assertSame(['queue-1', 'queue-2'], array_map('basename', glob($this->scanDir.'/queue-*') ?: []));
        $this->assertSame("APP_KEY=base64:x\nQUEUE_WORKERS=2\n", file_get_contents($this->base.'/.env-core'));
        $this->assertSame(2, QueueWorkers::configured());
        $this->assertFileDoesNotExist($this->base.'/s6-svscanctl.log');
    }

    public function test_applying_under_s6_rescans_and_clears_only_the_retired_workers_nobody_supervises(): void
    {
        mkdir($this->scanDir.'/.s6-svscan');
        QueueWorkers::writeServices(4, $this->scanDir);
        mkdir($this->scanDir.'/.queue-9-kept/supervise', 0755, true);
        $this->shim('s6-svok', 'case "$1" in *kept*) exit 0;; esac; exit 1');

        QueueWorkers::apply(2, $this->scanDir);

        $this->assertSame(['-an '.$this->scanDir], $this->calls('s6-svscanctl'));
        $this->assertSame([], glob($this->scanDir.'/.queue-3-*') ?: []);
        $this->assertSame([], glob($this->scanDir.'/.queue-4-*') ?: []);
        $this->assertDirectoryExists($this->scanDir.'/.queue-9-kept/supervise');
        $this->assertCount(3, $this->calls('s6-svok'));
    }

    public function test_a_retired_worker_is_removed_with_its_files_and_links_but_not_what_they_point_to(): void
    {
        $outside = $this->base.'/outside';
        mkdir($outside);
        touch($outside.'/keep.txt');
        $retired = $this->scanDir.'/.queue-7-gone';
        mkdir($retired.'/supervise', 0755, true);
        file_put_contents($retired.'/run', '#!/bin/sh');
        touch($retired.'/supervise/status');
        symlink($outside, $retired.'/linked');

        QueueWorkers::apply(1, $this->scanDir);

        $this->assertFileDoesNotExist($retired);
        $this->assertFileExists($outside.'/keep.txt');
    }

    private function shim(string $name, string $body): void
    {
        $log = escapeshellarg($this->base.'/'.$name.'.log');
        file_put_contents($this->shimDir.'/'.$name, "#!/bin/sh\necho \"\$@\" >> {$log}\n{$body}\n");
        chmod($this->shimDir.'/'.$name, 0755);
    }

    /** @return list<string> */
    private function calls(string $name): array
    {
        $log = $this->base.'/'.$name.'.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }
}
