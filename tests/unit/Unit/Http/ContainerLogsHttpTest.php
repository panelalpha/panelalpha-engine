<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Mcp\Tools\Api\Containers\ContainerServiceActionTool;
use App\Mcp\Tools\Api\Containers\ContainerServiceLogsTool;
use App\Models\User;
use App\System;
use App\System\Project\Dind\ContainerOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request as McpRequest;
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

    /** @var list<int> the timeout of each exec, in order */
    public array $timeouts = [];

    /** Output chunks the faked follow hands out, in order. */
    public array $followChunks = [];

    /** When set, the follow runs this for real instead of handing out followChunks. */
    public ?array $followCommand = null;

    public ?Process $followProcess = null;

    /** When set, answers each exec in place of "a log line\n", and may throw as a failed command does. */
    public ?\Closure $execAnswer = null;

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
                $this->test->timeouts[] = $timeout;
                if ($this->test->execAnswer !== null) {
                    return ($this->test->execAnswer)(end($this->test->commands)[13] ?? '');
                }

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
        $this->assertStringContainsString("'logs' '--tail=2000' '--no-color' '--since=10m' '--until=2026-10-03T12:00:00Z' '--' 'app'", $inner);

        $this->getJson('/api/projects/alice/containers/app/logs?lines=99999')->assertOk();
        $this->assertStringContainsString("'--tail=5000'", $this->innerCommand());
    }

    public function test_a_read_without_a_range_is_unchanged(): void
    {
        $this->getJson('/api/projects/alice/containers/app/logs')->assertOk();

        $this->assertStringEndsWith("'logs' '--tail=200' '--no-color' '--' 'app'", $this->innerCommand());
    }

    public function test_a_line_count_below_one_is_refused_before_anything_runs(): void
    {
        // Docker reads a negative tail as every line, past the 5000-line cap.
        foreach (['-1', '-5', '0'] as $lines) {
            foreach (['/logs', '/logs/stream'] as $path) {
                $this->getJson("/api/projects/alice/containers/app{$path}?lines={$lines}")
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('lines')
                    ->assertJsonPath('problems.0.field', 'lines')
                    ->assertJsonPath('problems.0.code', 'lines_invalid')
                    ->assertJsonPath('problems.0.message', 'The lines must be at least 1.')
                    ->assertJsonPath('problems.0.expected', 'a number of lines, at least 1; at most 5000 are returned')
                    ->assertJsonPath('problems.0.examples', ['200', '5000']);
            }
        }

        $response = app(ContainerServiceLogsTool::class)->handle(new McpRequest(['name' => 'alice', 'service' => 'app', 'lines' => -1]));
        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame(422, $payload['status']);
        $this->assertSame('lines_invalid', $payload['data']['problems'][0]['code']);

        $this->assertSame([], $this->commands);

        $this->getJson('/api/projects/alice/containers/app/logs?lines=1')->assertOk();
        $this->assertStringContainsString("'--tail=1'", $this->innerCommand());
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
            "'logs' '--tail=10' '--no-color' '--follow' '--timestamps' '--no-log-prefix' '--since=5m' '--' 'app'",
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

    public function test_a_name_that_starts_with_a_dash_is_refused_before_compose_runs(): void
    {
        // Compose would read these as its own options: --follow alone follows every service.
        foreach (['--follow', '-f', '--no-deps', '-'] as $name) {
            $this->getJson("/api/projects/alice/containers/{$name}/logs")
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Invalid service name']);
            $this->getJson("/api/projects/alice/containers/{$name}/logs/stream")
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Invalid service name']);
            $this->postJson("/api/projects/alice/containers/{$name}/action", ['action' => 'restart'])
                ->assertStatus(422)
                ->assertExactJson(['message' => 'Invalid service name']);
        }

        $this->assertSame([], $this->commands);
    }

    public function test_the_operations_refuse_a_name_that_starts_with_a_dash_themselves(): void
    {
        $dind = User::findByUsername('alice')->project(app(System::class))->runtime();
        $calls = [
            fn () => $dind->getServiceLogs('--follow'),
            fn () => $dind->assertServiceExists('--follow'),
            fn () => $dind->followServiceLogs('--follow', 10, null, static function (): void {
            }),
            fn () => $dind->serviceAction('--no-deps', 'restart'),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('A name starting with a dash must be refused.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('Invalid service name: -', $e->getMessage());
            }
        }

        $this->assertSame([], $this->commands);
    }

    public function test_a_trailing_newline_is_refused_in_a_service_name_and_a_time(): void
    {
        // `$` also matches before a final newline, so "app\n" passed as a name and "10m\n" as a time.
        $this->getJson('/api/projects/alice/containers/app%0A/logs')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid service name']);
        $this->getJson('/api/projects/alice/containers/app%0A/logs/stream')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid service name']);
        $this->postJson('/api/projects/alice/containers/app%0A/action', ['action' => 'restart'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid service name']);

        try {
            User::findByUsername('alice')->project(app(System::class))->runtime()->serviceAction("app\n", 'restart');
            $this->fail('A name with a trailing newline must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Invalid service name: app\n", $e->getMessage());
        }

        // REST trims query strings; an MCP call reaches the controller untrimmed.
        foreach (['since', 'until'] as $field) {
            $response = app(ContainerServiceLogsTool::class)->handle(
                new McpRequest(['name' => 'alice', 'service' => 'app', $field => "10m\n"]),
            );
            $this->assertTrue($response->isError());
            $payload = json_decode((string) $response->content(), true);
            $this->assertSame(422, $payload['status']);
            $this->assertSame("{$field}_invalid", $payload['data']['problems'][0]['code']);
        }

        $this->assertSame([], $this->commands);
    }

    public function test_an_unknown_service_is_refused_with_the_services_the_project_has(): void
    {
        // Compose's own answer, on stderr with exit 1.
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? "db\napp\nrelease\n"
            : throw new \Exception("no such service: nosuchsvc\n");

        foreach (['/logs', '/logs/stream'] as $path) {
            $this->commands = [];
            $this->getJson('/api/projects/alice/containers/nosuchsvc' . $path)
                ->assertStatus(422)
                ->assertJsonValidationErrors('service')
                ->assertJsonPath('problems.0.field', 'service')
                ->assertJsonPath('problems.0.code', 'service_not_found')
                ->assertJsonPath('problems.0.message', "The project has no service named 'nosuchsvc'. Its services: app, db, release.")
                ->assertJsonPath('problems.0.expected', 'one of: app, db, release')
                ->assertJsonPath('problems.0.examples', ['app', 'db', 'release']);

            // The list includes profiled services, which logs reads too; nothing was followed.
            $this->assertStringContainsString("'--profile' '*' 'config' '--services'", $this->innerCommand());
            $this->assertCount(2, $this->commands);
        }
    }

    public function test_an_unknown_service_is_refused_even_when_the_services_cannot_be_listed(): void
    {
        $this->execAnswer = static fn (string $inner): string => throw new \Exception(
            str_contains($inner, "'config'") ? 'some compose failure' : 'no such service: nosuchsvc',
        );

        $this->getJson('/api/projects/alice/containers/nosuchsvc/logs')
            ->assertStatus(422)
            ->assertJsonPath('problems.0.code', 'service_not_found')
            ->assertJsonPath('problems.0.message', "The project has no service named 'nosuchsvc'.")
            ->assertJsonMissingPath('problems.0.examples');
    }

    public function test_expected_lists_every_service_while_the_message_and_examples_are_capped(): void
    {
        $services = array_map(static fn (int $i): string => "s{$i}", range(1, 12));
        // Compose's order changes from call to call; the answer is sorted.
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? implode("\n", array_reverse($services)) . "\n"
            : throw new \Exception('no such service: nosuchsvc');

        $first10 = implode(', ', array_slice($services, 0, 10));
        $this->getJson('/api/projects/alice/containers/nosuchsvc/logs')
            ->assertStatus(422)
            ->assertJsonPath('problems.0.message', "The project has no service named 'nosuchsvc'. Its services: {$first10} and 2 more.")
            ->assertJsonPath('problems.0.expected', 'one of: ' . implode(', ', $services))
            ->assertJsonPath('problems.0.examples', ['s1', 's2', 's3', 's4', 's5']);
    }

    public function test_compose_quoting_the_name_or_saying_why_is_still_a_refusal(): void
    {
        foreach (['no such service: "nosuchsvc": not found', 'no such service: nosuchsvc: not found'] as $error) {
            $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
                ? "db\napp\n"
                : throw new \Exception($error . "\n");

            $this->getJson('/api/projects/alice/containers/nosuchsvc/logs')
                ->assertStatus(422)
                ->assertJsonPath('problems.0.code', 'service_not_found');
        }
    }

    public function test_compose_refusing_another_service_is_not_a_refusal_of_the_one_asked_for(): void
    {
        // A dependency compose cannot find, a longer name that starts with the one asked for,
        // another spelling, or a profiled service compose reports as disabled rather than missing.
        $cases = [
            ['nosuchsvc', 'no such service: other'],
            ['app', 'no such service: app-worker'],
            ['app', 'no such service: app.cache'],
            ['app', 'no such service: App'],
            ['release', 'no such service: release: disabled'],
            ['release', 'no such service: "release": disabled'],
        ];
        foreach ($cases as [$asked, $error]) {
            $this->commands = [];
            $this->execAnswer = static fn (): string => throw new \Exception($error . "\n");

            $this->getJson("/api/projects/alice/containers/{$asked}/logs")
                ->assertOk()
                ->assertJsonPath('data', $error . "\n");
            // Returned as the log, as before: no services were listed.
            $this->assertCount(1, $this->commands);
        }
    }

    public function test_compose_refusing_a_service_it_lists_keeps_its_own_error(): void
    {
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? "db\napp\n"
            : throw new \Exception("no such service: app\n");

        $this->getJson('/api/projects/alice/containers/app/logs')
            ->assertOk()
            ->assertJsonPath('data', "no such service: app\n");
        $this->assertStringContainsString("'--profile' '*' 'config' '--services'", $this->innerCommand());

        $stream = $this->get('/api/projects/alice/containers/app/logs/stream');
        $stream->assertOk();
        $this->assertStringContainsString('{"type":"finish"}', $stream->streamedContent());
    }

    public function test_the_stream_asks_compose_about_the_service_before_it_answers(): void
    {
        $this->followChunks = ["2026-10-03T12:00:00Z up\n"];

        $response = $this->get('/api/projects/alice/containers/app/logs/stream');

        $response->assertOk();
        $this->assertStringEndsWith("'logs' '--tail=0' '--no-color' '--' 'app'", $this->commands[0][13]);
        // Shorter than a read, since nothing is sent until compose answers.
        $this->assertSame(ContainerOperations::SERVICE_CHECK_SECONDS, $this->timeouts[0]);
        $this->assertStringContainsString('{"line":"up"', $response->streamedContent());
        $this->assertStringContainsString("'--follow'", $this->innerCommand());
    }

    public function test_another_compose_failure_is_still_returned_as_the_log(): void
    {
        $this->execAnswer = static fn (): string => throw new \Exception("no configuration file provided: not found\n");

        $this->getJson('/api/projects/alice/containers/app/logs')
            ->assertOk()
            ->assertJsonPath('data', "no configuration file provided: not found\n");
        $stream = $this->get('/api/projects/alice/containers/app/logs/stream');
        $stream->assertOk();
        $this->assertStringContainsString('{"type":"finish"}', $stream->streamedContent());
    }

    public function test_an_action_on_an_unknown_service_is_refused_the_same_way(): void
    {
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? "db\napp\n"
            : throw new \Exception("no such service: nosuchsvc\n");

        foreach (['start', 'stop', 'restart'] as $action) {
            $this->commands = [];
            $this->postJson('/api/projects/alice/containers/nosuchsvc/action', ['action' => $action])
                ->assertStatus(422)
                ->assertJsonValidationErrors('service')
                ->assertJsonPath('problems.0.field', 'service')
                ->assertJsonPath('problems.0.code', 'service_not_found')
                ->assertJsonPath('problems.0.message', "The project has no service named 'nosuchsvc'. Its services: app, db.")
                ->assertJsonPath('problems.0.expected', 'one of: app, db')
                ->assertJsonPath('problems.0.examples', ['app', 'db']);
            $this->assertStringEndsWith("'{$action}' '--' 'nosuchsvc'", $this->commands[0][13]);
        }
        // A refused stop is not a stop the health sweep should respect.
        $this->assertFalse(User::findByUsername('alice')->isAppStoppedByRequest());
    }

    public function test_an_action_on_a_known_service_is_unchanged(): void
    {
        $this->postJson('/api/projects/alice/containers/app/action', ['action' => 'restart'])
            ->assertOk()
            ->assertExactJson(['stdout' => "a log line\n", 'stderr' => '', 'exit_code' => 0]);
        $this->assertStringEndsWith("'restart' '--' 'app'", $this->innerCommand());

        $this->execAnswer = static fn (): string => throw new \Exception("no such service: other\n");
        $this->postJson('/api/projects/alice/containers/app/action', ['action' => 'restart'])
            ->assertStatus(500)
            ->assertExactJson(['stdout' => '', 'stderr' => "no such service: other\n", 'exit_code' => 1]);

        // Compose refusing a service its own list has is not an unknown service either.
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? "db\napp\n"
            : throw new \Exception("no such service: app\n");
        $this->postJson('/api/projects/alice/containers/app/action', ['action' => 'restart'])
            ->assertStatus(500)
            ->assertExactJson(['stdout' => '', 'stderr' => "no such service: app\n", 'exit_code' => 1]);
    }

    public function test_the_mcp_tools_report_an_unknown_service_as_a_tool_error(): void
    {
        $this->execAnswer = static fn (string $inner): string => str_contains($inner, "'config' '--services'")
            ? "db\napp\n"
            : throw new \Exception("no such service: nosuchsvc\n");

        $calls = [
            [ContainerServiceActionTool::class, ['name' => 'alice', 'service' => 'nosuchsvc', 'action' => 'restart']],
            [ContainerServiceLogsTool::class, ['name' => 'alice', 'service' => 'nosuchsvc']],
        ];
        foreach ($calls as [$tool, $arguments]) {
            $response = app($tool)->handle(new McpRequest($arguments));

            $this->assertTrue($response->isError());
            $payload = json_decode((string) $response->content(), true);
            $this->assertSame(422, $payload['status']);
            $this->assertSame('service_not_found', $payload['data']['problems'][0]['code']);
            $this->assertSame(['app', 'db'], $payload['data']['problems'][0]['examples']);
        }
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
