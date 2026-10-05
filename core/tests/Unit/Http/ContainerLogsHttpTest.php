<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Project\Dind\ContainerOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\TestCase;

/**
 * Runtime logs of a deployed service: a read was capped at 500 lines with no
 * time range, and there was no way to follow the log at all.
 */
class ContainerLogsHttpTest extends TestCase
{
    private string $tmpRoot;

    /** @var list<list<string>> */
    public array $commands = [];

    /** Output chunks the faked follow hands out, in order. */
    public array $followChunks = [];

    /** When set, the follow runs this for real instead of handing out followChunks. */
    public ?array $followCommand = null;

    public ?Process $followProcess = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        $this->tmpRoot = sys_get_temp_dir() . '/pa-container-logs-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->tmpRoot . '/home/alice/project', 0777, true);
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->tmpRoot . '/home/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $test = $this;
        $this->app->instance(System::class, new class ($this->tmpRoot, $test) extends System {
            public function __construct(private string $root, private ContainerLogsHttpTest $test)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->commands[] = (array) $cmd;

                return "a log line\n";
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return FakeProcess::forCommand($cmd);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?\App\Lib\Deploy\DeployLog\StepWatchdog $watchdog = null,
            ): Process {
                $this->test->commands[] = (array) $cmd;
                if ($this->test->followCommand !== null) {
                    // As HostProcess does it without a watchdog: started with no
                    // callback, onStart gets the process, wait() hands out the rest.
                    $process = $this->test->followProcess = new Process($this->test->followCommand);
                    $process->start();
                    if ($onStart !== null) {
                        $onStart($process);
                    }
                    $process->wait($onOutput);

                    return $process;
                }
                foreach ($this->test->followChunks as $chunk) {
                    $onOutput(Process::OUT, $chunk);
                }

                return FakeProcess::ok();
            }
        });

        $this->withoutMiddleware(Authenticate::class);
        User::query()->create([
            'username' => 'alice',
            'status' => 'active',
            'details' => ['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'deploy_strategy' => 'compose'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->followProcess?->stop(0);
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_read_returns_up_to_5000_lines_in_a_time_range(): void
    {
        $this->getJson('/api/projects/alice/containers/app/logs?lines=2000&since=10m&until=2026-10-03T12:00:00Z')
            ->assertOk()
            ->assertJsonPath('data', "a log line\n");

        $inner = $this->innerCommand();
        $this->assertStringContainsString("'logs' '--tail=2000' '--no-color' '--since=10m' '--until=2026-10-03T12:00:00Z' 'app'", $inner);

        $this->getJson('/api/projects/alice/containers/app/logs?lines=99999')->assertOk();
        $this->assertStringContainsString("'--tail=5000'", $this->innerCommand());
    }

    public function test_a_read_without_a_range_is_unchanged(): void
    {
        $this->getJson('/api/projects/alice/containers/app/logs')->assertOk();

        $this->assertStringEndsWith("'logs' '--tail=200' '--no-color' 'app'", $this->innerCommand());
    }

    public function test_a_malformed_time_is_refused_before_anything_runs(): void
    {
        foreach (['yesterday', '10 minutes', '10m; rm -rf /', '--follow'] as $since) {
            $this->getJson('/api/projects/alice/containers/app/logs?since=' . urlencode($since))
                ->assertStatus(422)
                ->assertJsonValidationErrors('since')
                ->assertJsonPath('problems.0.code', 'since_invalid');
        }
        $this->getJson('/api/projects/alice/containers/app/logs/stream?since=soon')->assertStatus(422);

        $this->assertSame([], $this->commands);
    }

    public function test_the_stream_follows_the_log_as_ndjson_and_ends_with_finish(): void
    {
        $this->followChunks = [
            "2026-10-03T12:00:00.000000001Z GET / 200\n2026-10-03T12:00:01",
            ".5Z GET /about 200\n",
            "no timestamp here\n",
        ];

        $response = $this->get('/api/projects/alice/containers/app/logs/stream?lines=10&since=5m');

        $response->assertOk();
        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $frames = array_map(
            fn (string $l) => json_decode($l, true),
            array_values(array_filter(explode("\n", $response->streamedContent()))),
        );
        $this->assertSame([
            ['line' => 'GET / 200', 'ts' => '2026-10-03T12:00:00.000000001Z'],
            ['line' => 'GET /about 200', 'ts' => '2026-10-03T12:00:01.5Z'],
            ['line' => 'no timestamp here', 'ts' => null],
            ['type' => 'finish'],
        ], $frames);

        $inner = $this->innerCommand();
        $this->assertMatchesRegularExpression("/^'timeout' '600' 'env' 'PANELALPHA_LOG_FOLLOW=[0-9a-f]{12}' 'PWD=/", $inner);
        $this->assertStringContainsString(
            "'logs' '--tail=10' '--no-color' '--follow' '--timestamps' '--no-log-prefix' '--since=5m' 'app'",
            $inner,
        );
    }

    public function test_a_client_that_leaves_stops_the_follow_inside_the_account(): void
    {
        $this->followChunks = ["2026-10-03T12:00:00Z one\n", "2026-10-03T12:00:01Z two\n"];
        $dind = User::findByUsername('alice')->project(app(System::class))->runtime();
        $seen = [];

        try {
            $dind->followServiceLogs('app', 10, null, function (string $line) use (&$seen): void {
                $seen[] = $line;
                throw new \RuntimeException('gone');
            });
            $this->fail('The client leaving must end the follow.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gone', $e->getMessage());
        }

        $this->assertSame(['one'], $seen);
        preg_match("/PANELALPHA_LOG_FOLLOW=[0-9a-f]{12}/", $this->commands[0][13], $tag);
        $this->assertSame("'pkill' '-TERM' '-f' '{$tag[0]}'", $this->innerCommand());
    }

    public function test_a_quiet_service_gets_heartbeats_between_lines(): void
    {
        $this->followCommand = ['sh', '-c', 'echo "2026-10-03T11:59:59Z first"; sleep 3; echo "2026-10-03T12:00:00Z late"'];

        $response = $this->get('/api/projects/alice/containers/app/logs/stream');

        $frames = array_map(
            fn (string $l) => json_decode($l, true),
            array_values(array_filter(explode("\n", $response->streamedContent()))),
        );
        $this->assertSame([
            ['line' => 'first', 'ts' => '2026-10-03T11:59:59Z'],
            ['type' => 'heartbeat'],
            ['line' => 'late', 'ts' => '2026-10-03T12:00:00Z'],
            ['type' => 'finish'],
        ], $frames);
    }

    public function test_a_client_that_leaves_a_quiet_service_stops_the_follow_without_waiting_for_a_line(): void
    {
        $this->followCommand = ['sleep', '30'];
        $dind = User::findByUsername('alice')->project(app(System::class))->runtime();
        $started = microtime(true);
        $lines = 0;

        try {
            $dind->followServiceLogs(
                'app',
                10,
                null,
                function () use (&$lines): void {
                    $lines++;
                },
                function (): void {
                    throw new \RuntimeException('gone');
                },
            );
            $this->fail('The client leaving must end the follow.');
        } catch (\RuntimeException $e) {
            $this->assertSame('gone', $e->getMessage());
        }

        $this->assertSame(0, $lines);
        $this->assertLessThan(ContainerOperations::FOLLOW_IDLE_SECONDS + 2, microtime(true) - $started);
        preg_match("/PANELALPHA_LOG_FOLLOW=[0-9a-f]{12}/", $this->commands[0][13], $tag);
        $this->assertSame("'pkill' '-TERM' '-f' '{$tag[0]}'", $this->innerCommand());
    }

    public function test_split_timestamp_leaves_a_line_without_one_alone(): void
    {
        $this->assertSame(['x y', '2026-10-03T12:00:00Z'], ContainerOperations::splitTimestamp('2026-10-03T12:00:00Z x y'));
        $this->assertSame(['plain', null], ContainerOperations::splitTimestamp('plain'));
    }

    /** The command run inside the account, as `su -c` receives it. */
    private function innerCommand(): string
    {
        $last = end($this->commands);
        $this->assertIsArray($last);
        $this->assertSame(['su', '-s', '/bin/bash', 'alice', '-c'], array_slice($last, 8, 5));

        return $last[13];
    }
}
