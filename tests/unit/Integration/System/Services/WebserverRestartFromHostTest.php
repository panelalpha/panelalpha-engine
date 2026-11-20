<?php

namespace Tests\Integration\System\Services;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * sites-http is restarted from the host only while `nginx -t` passes, so a broken vhost never
 * crash-loops the running webserver; and the change-webserver script reports why it failed.
 */
class WebserverRestartFromHostTest extends TestCase
{
    private const LATEST = '/opt/panelalpha/log/change-webserver/latest';

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        @unlink(self::LATEST.'/pid');
        @rmdir(self::LATEST);
        parent::tearDown();
    }

    public function test_a_passing_config_test_lets_the_restart_run(): void
    {
        $host = $this->host('nginx-proxy', FakeProcess::ok('nginx: configuration file /etc/nginx/nginx.conf test is successful'));

        $host->webserver()->restartWebserverContainerFromHost();

        $this->assertSame(['compose.nginxConfigTest', 'compose.restart'], $host->actions());
    }

    public function test_a_failing_config_test_refuses_the_restart_and_logs_what_nginx_said(): void
    {
        Log::spy();
        $verdict = 'nginx: [emerg] unknown directive "lisen" in /etc/nginx/vhosts/alice.test.conf:3';
        $host = $this->host('nginx', FakeProcess::of(1, '', "WARN compose noise\n{$verdict}\nnginx: configuration file test failed"));

        try {
            $host->webserver()->restartWebserverContainerFromHost();
            $this->fail('the restart has to be refused');
        } catch (\Exception $e) {
            $this->assertStringContainsString('does not pass `nginx -t`', $e->getMessage());
            $this->assertStringNotContainsString('alice.test', $e->getMessage());
        }

        $this->assertSame(['compose.nginxConfigTest'], $host->actions());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Webserver config test failed'
                && $context['webserver'] === 'nginx' && str_contains($context['output'], $verdict)
        );
    }

    public function test_a_failed_exec_without_an_nginx_verdict_does_not_block_the_restart(): void
    {
        $host = $this->host('nginx-proxy', FakeProcess::of(126, '', 'OCI runtime exec failed: container is paused'));

        $host->webserver()->restartWebserverContainerFromHost();

        $this->assertSame(['compose.nginxConfigTest', 'compose.restart'], $host->actions());
    }

    public function test_a_config_test_that_cannot_run_is_logged_and_the_restart_runs(): void
    {
        Log::spy();
        $host = $this->host('nginx-proxy', new \RuntimeException('broker refused'));

        $host->webserver()->restartWebserverContainerFromHost();

        $this->assertSame(['compose.nginxConfigTest', 'compose.restart'], $host->actions());
        Log::shouldHaveReceived('warning')->once()->with('Webserver config test could not be run: broker refused');
    }

    public function test_another_webserver_is_restarted_without_a_config_test(): void
    {
        $host = $this->host('apache', FakeProcess::failed('[emerg] never asked'));

        $host->webserver()->restartWebserverContainerFromHost();

        $this->assertSame(['compose.restart'], $host->actions());
    }

    public function test_an_undetectable_webserver_is_restarted_without_a_config_test(): void
    {
        $host = $this->host(null, FakeProcess::failed('[emerg] never asked'));

        $host->webserver()->restartWebserverContainerFromHost();

        $this->assertSame(['compose.restart'], $host->actions());
    }

    public function test_a_failed_change_webserver_script_reports_its_error_output_and_exit_code(): void
    {
        $host = $this->host('nginx', FakeProcess::of(3, 'partial output', 'license rejected'));

        $this->expectExceptionMessage('Failed to run change webserver script: license rejected (exit code 3)');

        $host->webserver()->runChangeWebserverScript('litespeed', 'SERIAL');
    }

    public function test_a_failed_change_webserver_script_without_error_output_reports_its_output(): void
    {
        $host = $this->host('nginx', FakeProcess::of(2, 'nothing to change'));

        $this->expectExceptionMessage('Failed to run change webserver script: nothing to change (exit code 2)');

        $host->webserver()->runChangeWebserverScript('apache');
    }

    public function test_a_run_that_left_no_pid_file_is_not_running(): void
    {
        $host = $this->host('nginx', FakeProcess::ok(), latestExists: true);

        $this->assertFalse($host->webserver()->isChangeWebserverScriptRunning());
        $this->assertSame([], $host->actions());
    }

    public function test_a_pid_file_that_holds_no_pid_is_not_running(): void
    {
        if (! @mkdir(self::LATEST, 0777, true) && ! is_dir(self::LATEST)) {
            $this->markTestSkipped('Cannot create the change-webserver log dir under /opt/panelalpha');
        }
        $host = $this->host('nginx', FakeProcess::ok(), latestExists: true);

        foreach (["not-a-pid\n", "0\n"] as $pid) {
            file_put_contents(self::LATEST.'/pid', $pid);
            $this->assertFalse($host->webserver()->isChangeWebserverScriptRunning(), $pid);
        }
        $this->assertSame([], $host->actions());
    }

    /** A host whose sites-http runs $webserver (null: compose cannot tell) and whose host commands answer $answer. */
    private function host(?string $webserver, Process|\Throwable $answer, bool $latestExists = false): System
    {
        return new class ($webserver, $answer, $latestExists) extends System {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private ?string $webserver, private Process|\Throwable $answer, private bool $latestExists) {}

            public function engineDirPath(): string
            {
                return '/nonexistent/engine';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->webserver === null ? 'not json' : json_encode(['Labels' => "com.docker.compose.service=sites-http,com.panelalpha.webserver={$this->webserver}"]);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = array_values((array) $cmd);
                $this->commands[] = $argv;
                if (LocalRoot::action($argv) === 'compose.restart') {
                    return FakeProcess::ok();
                }
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->latestExists) extends Filesystem {
                    public function __construct(System $system, private bool $latestExists)
                    {
                        parent::__construct($system);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return $this->latestExists;
                    }
                };
            }

            /** @return list<?string> the broker actions asked for, in order */
            public function actions(): array
            {
                return array_map(static fn (array $argv): ?string => LocalRoot::action($argv), $this->commands);
            }
        };
    }
}
