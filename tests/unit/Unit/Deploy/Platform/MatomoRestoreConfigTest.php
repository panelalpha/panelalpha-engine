<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use PHPUnit\Framework\TestCase;

/**
 * Matomo's restore-config, run as a rebuild's entrypoint runs it.
 *
 * Writing the database section over a database the installer never finished
 * made Matomo call itself installed over empty tables and refuse to run its
 * installer at all. The check is a `php -r` against the account's database,
 * faked here by a `php` that exits with a scripted code per call: 0 a
 * superuser exists, 1 no finished install, 2 the database is unreachable.
 */
class MatomoRestoreConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-matomo-restore-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/bin', 0o755, true);
        mkdir($this->dir . '/fake-php', 0o755, true);
        mkdir($this->dir . '/app/config', 0o755, true);

        file_put_contents($this->dir . '/fake-php/php', <<<'SH'
            #!/bin/sh
            n=$(cat "$FAKE_DIR/calls" 2>/dev/null || echo 0)
            n=$((n + 1))
            echo "$n" >"$FAKE_DIR/calls"
            code=$(sed -n "${n}p" "$FAKE_DIR/codes")
            [ -n "$code" ] || code=$(tail -n 1 "$FAKE_DIR/codes")
            exit "$code"
            SH);
        // The retry waits are counted, not slept.
        file_put_contents($this->dir . '/bin/sleep', <<<'SH'
            #!/bin/sh
            n=$(cat "$FAKE_DIR/sleeps" 2>/dev/null || echo 0)
            echo $((n + 1)) >"$FAKE_DIR/sleeps"
            SH);
        chmod($this->dir . '/fake-php/php', 0o755);
        chmod($this->dir . '/bin/sleep', 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function command(): PlatformCommand
    {
        foreach (PlatformRegistry::find('matomo')?->stage(PlatformStage::UPGRADE) ?? [] as $command) {
            if ($command->id === 'restore-config') {
                return $command;
            }
        }
        $this->fail('matomo has no restore-config command in its upgrade stage');
    }

    /**
     * @param list<int>|null $codes what the database check answers, call by
     *        call; null runs the real php
     * @return array{status: int, output: string, checks: int, sleeps: int}
     */
    private function boot(?array $codes, string $port = '3306'): array
    {
        $path = $this->dir . '/bin:' . getenv('PATH');
        if ($codes !== null) {
            file_put_contents($this->dir . '/codes', implode("\n", $codes) . "\n");
            $path = $this->dir . '/fake-php:' . $path;
        }
        $run = str_replace('/app/', $this->dir . '/app/', $this->command()->run);
        $env = 'PATH=' . escapeshellarg($path)
            . ' FAKE_DIR=' . escapeshellarg($this->dir)
            . ' DB_HOST=127.0.0.1 DB_PORT=' . escapeshellarg($port)
            . ' DB_USERNAME=acct DB_PASSWORD=secret DB_DATABASE=acct_app APP_URL=https://stats.example.com';
        // set -e as in the generated entrypoint, so a failed step ends the boot.
        exec($env . ' sh -c ' . escapeshellarg("set -e\n{$run}\necho booted") . ' 2>&1', $output, $status);

        return [
            'status' => $status,
            'output' => implode("\n", $output),
            'checks' => (int) trim((string) @file_get_contents($this->dir . '/calls')),
            'sleeps' => (int) trim((string) @file_get_contents($this->dir . '/sleeps')),
        ];
    }

    private function config(): ?string
    {
        $file = $this->dir . '/app/config/config.ini.php';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public function test_a_finished_install_gets_its_database_section_back(): void
    {
        $boot = $this->boot([0]);

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertStringContainsString('booted', $boot['output']);
        $this->assertStringContainsString("[database]\nhost = \"127.0.0.1\"", (string) $this->config());
        $this->assertStringContainsString('dbname = "acct_app"', (string) $this->config());
        $this->assertStringContainsString('trusted_hosts[] = "stats.example.com"', (string) $this->config());
    }

    public function test_an_unfinished_install_is_left_to_the_installer(): void
    {
        $boot = $this->boot([1]);

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertStringContainsString('booted', $boot['output']);
        $this->assertNull($this->config(), 'a config here makes Matomo refuse its own installer');
        $this->assertStringContainsString('installer never finished', $boot['output']);
        $this->assertSame(1, $boot['checks']);
    }

    public function test_a_database_that_comes_up_late_is_waited_for(): void
    {
        $boot = $this->boot([2, 2, 0]);

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertSame(3, $boot['checks']);
        $this->assertSame(2, $boot['sleeps']);
        $this->assertStringContainsString('[database]', (string) $this->config());
    }

    public function test_an_unreachable_database_fails_the_boot_rather_than_guess(): void
    {
        $boot = $this->boot([2]);

        $this->assertNotSame(0, $boot['status']);
        $this->assertStringNotContainsString('booted', $boot['output']);
        $this->assertStringContainsString('cannot read the database', $boot['output']);
        $this->assertNull($this->config());
        $this->assertSame(31, $boot['checks'], 'bounded');
    }

    public function test_a_check_that_breaks_is_not_read_as_installed(): void
    {
        $boot = $this->boot([255]);

        $this->assertNotSame(0, $boot['status']);
        $this->assertNull($this->config());
        $this->assertSame(1, $boot['checks'], 'only an unreachable database is retried');
    }

    public function test_a_config_matomo_wrote_is_never_touched(): void
    {
        $own = "; <?php exit; ?> DO NOT REMOVE THIS LINE\n[database]\nhost = \"elsewhere\"\n[General]\nsalt = \"abc\"\n";
        file_put_contents($this->dir . '/app/config/config.ini.php', $own);

        $boot = $this->boot([0]);

        $this->assertSame(0, $boot['status'], $boot['output']);
        $this->assertSame($own, $this->config());
        $this->assertSame(0, $boot['checks'], 'no database check when there is nothing to restore');
    }

    /**
     * The real `php -r`, against a port nothing listens on: it must parse,
     * and a refused connection must read as unreachable (retried), not as a
     * broken check.
     */
    public function test_the_database_check_reports_a_refused_connection_as_unreachable(): void
    {
        $boot = $this->boot(null, '1');

        $this->assertNotSame(0, $boot['status']);
        $this->assertStringContainsString('cannot read the database', $boot['output']);
        $this->assertSame(30, $boot['sleeps'], $boot['output']);
        $this->assertNull($this->config());
    }
}
