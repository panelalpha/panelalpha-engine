<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ChangeWebserverWebserverTest extends TestCase
{
    private string $logRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logRoot = sys_get_temp_dir() . '/pa-change-ws-' . bin2hex(random_bytes(4));
        mkdir($this->logRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->logRoot);
        parent::tearDown();
    }

    public function test_get_latest_change_webserver_info_returns_null_when_log_dir_missing(): void
    {
        $system = $this->systemWithLogRoot($this->logRoot . '/missing');

        $this->assertNull($system->webserver()->getLatestChangeWebserverInfo());
    }

    public function test_get_latest_change_webserver_info_reads_log_artifacts(): void
    {
        $latest = $this->logRoot . '/latest';
        mkdir($latest, 0777, true);
        file_put_contents("{$latest}/pid", "4242\n");
        file_put_contents("{$latest}/exit_code", "0\n");
        file_put_contents("{$latest}/stdout", "line1\n\x1B[31mcolored\x1B[0m\n");
        file_put_contents("{$latest}/stderr", "warn\n");
        file_put_contents("{$latest}/from_version", 'nginx');
        file_put_contents("{$latest}/to_version", 'litespeed');
        touch($latest, 1_700_000_000);
        touch("{$latest}/exit_code", 1_700_000_100);

        $info = $this->systemWithLogRoot($this->logRoot)->webserver()->getLatestChangeWebserverInfo();

        $this->assertNotNull($info);
        $this->assertSame(1_700_000_000, $info['started_at']);
        $this->assertSame(1_700_000_100, $info['finished_at']);
        $this->assertSame(4242, $info['pid']);
        $this->assertSame(0, $info['exit_code']);
        $this->assertStringContainsString('colored', (string) $info['tail_stdout']);
        $this->assertStringNotContainsString("\x1B[", (string) $info['tail_stdout']);
        $this->assertSame('warn', trim((string) $info['tail_stderr']));
        $this->assertSame('nginx', $info['from_version']);
        $this->assertSame('litespeed', $info['to_version']);
        // The fixture is read through a mapped filesystem; the path reported is the host's.
        $this->assertSame('/opt/panelalpha/log/change-webserver/latest', $info['logs_path']);
    }

    public function test_run_change_webserver_script_without_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->lastCommand = is_array($cmd) ? $cmd : [$cmd];
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };

        $system->webserver()->runChangeWebserverScript('litespeed');

        $this->assertNotNull($system->lastCommand);
        $this->assertContains('--set', $system->lastCommand);
        $this->assertContains('litespeed', $system->lastCommand);
        $this->assertContains('--background', $system->lastCommand);
        $this->assertContains(
            $system->engineDirPath() . '/webserver.sh',
            $system->lastCommand
        );
        $this->assertFalse(
            (bool) array_filter(
                $system->lastCommand,
                fn ($arg) => str_starts_with((string) $arg, '--serial-no=')
            )
        );
    }

    public function test_run_change_webserver_script_with_serial(): void
    {
        $system = new class extends System {
            public ?array $lastCommand = null;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->lastCommand = is_array($cmd) ? $cmd : [$cmd];
                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };

        $system->webserver()->runChangeWebserverScript('litespeed', 'SERIAL123');

        $this->assertNotNull($system->lastCommand);
        $this->assertContains('--serial-no=SERIAL123', $system->lastCommand);
        $this->assertContains('--background', $system->lastCommand);
    }

    public function test_is_change_webserver_script_running_false_when_log_dir_missing(): void
    {
        $system = $this->systemWithLogRoot($this->logRoot . '/missing');

        $this->assertFalse($system->webserver()->isChangeWebserverScriptRunning());
    }

    public function test_webserver_script_parse_args_handles_php_argument_order(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('bash integration script is not run on Windows CI hosts');
        }

        $scriptPath = dirname(__DIR__, 4) . '/scripts/webserver-parse-args.test.sh';
        $this->assertFileExists($scriptPath);

        $process = Process::fromShellCommandline('bash ' . escapeshellarg($scriptPath));
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            $process->getOutput() . $process->getErrorOutput()
        );
        $this->assertStringContainsString('PASS:', $process->getOutput());
    }

    public function test_is_change_webserver_script_running_uses_kill_zero_on_pid(): void
    {
        $latestLink = '/opt/panelalpha/log/change-webserver/latest';
        if (!@mkdir($latestLink, 0777, true) && !is_dir($latestLink)) {
            $this->markTestSkipped('Cannot create change-webserver log dir under /opt/panelalpha');
        }
        file_put_contents("{$latestLink}/pid", "99\n");

        try {
            $logRoot = dirname($latestLink);
            $system = $this->systemWithLogRoot($logRoot, killZeroExitCode: 0);
            $this->assertTrue($system->webserver()->isChangeWebserverScriptRunning());

            $system = $this->systemWithLogRoot($logRoot, killZeroExitCode: 1);
            $this->assertFalse($system->webserver()->isChangeWebserverScriptRunning());
        } finally {
            @unlink("{$latestLink}/pid");
            @rmdir($latestLink);
            @rmdir(dirname($latestLink));
            @rmdir(dirname(dirname($latestLink)));
        }
    }

    private function systemWithLogRoot(string $logRoot, ?int $killZeroExitCode = null): System
    {
        return new class ($logRoot, $killZeroExitCode) extends System {
            public function __construct(
                private string $logRoot,
                private ?int $killZeroExitCode,
            ) {
            }

            public function webserver(): Webserver
            {
                return new Webserver($this);
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->logRoot) extends Filesystem {
                    public function __construct(
                        System $system,
                        private string $logRoot,
                    ) {
                        parent::__construct($system);
                    }

                    public function directoryExists(string $path): bool
                    {
                        if ($path === '/opt/panelalpha/log/change-webserver/latest') {
                            return is_dir($this->logRoot . '/latest');
                        }

                        return is_dir($path);
                    }

                    public function cat(string $path): ?string
                    {
                        $mapped = $this->mapPath($path);
                        if ($mapped === null || !is_file($mapped)) {
                            return null;
                        }

                        return file_get_contents($mapped) ?: null;
                    }

                    public function tail(string $path, int $lines): ?string
                    {
                        $mapped = $this->mapPath($path);
                        if ($mapped === null || !is_file($mapped)) {
                            return null;
                        }
                        $content = file($mapped, FILE_IGNORE_NEW_LINES);
                        if ($content === false) {
                            return null;
                        }

                        return implode("\n", array_slice($content, -$lines));
                    }

                    public function mtime(string $path): ?int
                    {
                        $mapped = $this->mapPath($path);
                        if ($mapped === null || !file_exists($mapped)) {
                            return null;
                        }

                        return filemtime($mapped) ?: null;
                    }

                    private function mapPath(string $path): ?string
                    {
                        $prefix = '/opt/panelalpha/log/change-webserver/latest';
                        if (!str_starts_with($path, $prefix)) {
                            return $path;
                        }

                        return $this->logRoot . '/latest' . substr($path, strlen($prefix));
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if ($this->killZeroExitCode !== null && is_array($cmd) && ($cmd[5] ?? '') === 'kill') {
                    $process = Process::fromShellCommandline('exit ' . $this->killZeroExitCode);
                    $process->run();

                    return $process;
                }

                $process = Process::fromShellCommandline('true');
                $process->run();

                return $process;
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
