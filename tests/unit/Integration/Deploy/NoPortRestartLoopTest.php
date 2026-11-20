<?php

namespace Tests\Integration\Deploy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A stack that publishes no port and crash-loops (Plausible CE, whose
 * compose leaves the port to your own proxy) read "Deploy finished
 * successfully": the restart-loop check was only asked when there was a
 * port to probe.
 */
class NoPortRestartLoopTest extends TestCase
{
    private string $username = '';

    private string $compose = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'noport' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->compose = tempnam(sys_get_temp_dir(), 'pa-compose-');
        file_put_contents($this->compose, "services:\n  worker:\n    image: alpine:3\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->compose);
        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string> the deploy log after report() with these two inspect snapshots
     */
    private function report(array $before, array $after): array
    {
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $system = new class ([$before, $after]) extends System {
            public function __construct(private array $snapshots)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (LocalRoot::action((array) $cmd) === 'account-docker.containers') {
                    return (string) json_encode(array_shift($this->snapshots));
                }

                return LocalRoot::action((array) $cmd) === 'account-docker.listContainers' ? '[{"Id":"worker1","Names":["/project-worker-1"]}]' : '';
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('userAppComposeFileToRun')->willReturn($this->compose);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeProjectName')->willReturn('project');
        $dind->method('userAppComposeCommand')->willReturnCallback(static fn (array $rest): array => array_merge(['docker', 'compose'], $rest));

        $health = new class ($dind) extends AppHealth {
            protected function settle(): void
            {
            }
        };
        $health->report();

        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }

    public function test_a_crash_loop_without_a_published_port_is_reported(): void
    {
        $lines = $this->report(
            [self::worker('running', 0, 0)],
            [self::worker('restarting', 1, 7)]
        );

        $this->assertContains('Health check: the application publishes no port to probe', $lines);
        $failed = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Check failed: The application is restarting')));
        $this->assertCount(1, $failed, implode("\n", $lines));
        $this->assertStringContainsString('worker (restarting', $failed[0]);
    }

    public function test_a_steady_worker_without_a_port_is_left_alone(): void
    {
        $worker = [self::worker('running', 0, 0)];

        $lines = $this->report($worker, $worker);

        $this->assertContains('Health check: the application publishes no port to probe', $lines);
        $this->assertSame([], array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Check failed'))));
    }

    /** @return array<string, mixed> the account daemon's inspect of the stack's one container */
    private static function worker(string $status, int $exitCode, int $restarts): array
    {
        return [
            'Name' => '/project-worker-1',
            'Config' => ['Labels' => ['com.docker.compose.service' => 'worker']],
            'State' => ['Status' => $status, 'ExitCode' => $exitCode],
            'RestartCount' => $restarts,
        ];
    }
}
