<?php

namespace Tests\Integration\Support;

use App\Support\QueueWorkers;
use PHPUnit\Framework\TestCase;

/** The scheduler and the queue workers start only once no migration is pending. */
class CoreServicesWaitForMigrationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-migration-gate-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0755, true);
        mkdir($this->dir . '/html');
        $sleep = trim((string) shell_exec('command -v sleep'));
        $this->stub('php', <<<SH
            #!/bin/sh
            echo "\$*" >>{$this->dir}/calls
            [ "\$2" = migrate:status ] || exit 0
            n=\$(cat {$this->dir}/pending 2>/dev/null || echo 0)
            [ "\$n" -gt 0 ] || exit 0
            echo \$((n - 1)) >{$this->dir}/pending
            exit 1
            SH);
        $this->stub('s6-setuidgid', "#!/bin/sh\nshift\nexec \"\$@\"\n");
        $this->stub('sleep', <<<SH
            #!/bin/sh
            [ "\$1" = 5 ] && exit 0
            {$sleep} 0.3
            kill \$PPID
            SH);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_queue_worker_waits_until_the_migrations_ran(): void
    {
        $this->runService(QueueWorkers::runScript(), 2);

        $this->assertSame(
            ['artisan migrate:status --pending=1', 'artisan migrate:status --pending=1', 'artisan migrate:status --pending=1'],
            array_slice($this->calls(), 0, 3),
        );
        $this->assertStringStartsWith('artisan queue:work database', $this->calls()[3]);
    }

    public function test_the_scheduler_waits_until_the_migrations_ran(): void
    {
        $this->runService((string) file_get_contents(dirname(__DIR__, 4) . '/config/core/services/scheduler/run'), 2);

        $this->assertSame(
            ['artisan migrate:status --pending=1', 'artisan migrate:status --pending=1', 'artisan migrate:status --pending=1', 'artisan schedule:run'],
            $this->calls(),
        );
    }

    public function test_nothing_waits_once_the_migrations_ran(): void
    {
        $this->runService(QueueWorkers::runScript(), 0);

        $this->assertSame('artisan migrate:status --pending=1', $this->calls()[0]);
        $this->assertStringStartsWith('artisan queue:work database', $this->calls()[1]);
    }

    private function runService(string $script, int $pending): void
    {
        file_put_contents($this->dir . '/pending', (string) $pending);
        file_put_contents($this->dir . '/run', str_replace('/var/www/html', $this->dir . '/html', $script));
        exec(sprintf('PATH=%s:$PATH sh %s >/dev/null 2>&1', escapeshellarg($this->dir . '/bin'), escapeshellarg($this->dir . '/run')));
        usleep(300_000);
    }

    /** @return list<string> */
    private function calls(): array
    {
        return file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents($this->dir . '/bin/' . $name, $body . "\n");
        chmod($this->dir . '/bin/' . $name, 0755);
    }
}
