<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhpErrorLogHttpTest extends TestCase
{
    private string $root;

    /** @var list<list<string>> */
    public array $commands = [];

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
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        (require base_path('database/migrations/2022_09_06_160855_create_domains_table.php'))->up();

        $this->root = sys_get_temp_dir() . '/pa-php-error-log-http-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);

        $test = $this;
        $this->app->instance(System::class, new class ($this->root, $test) extends System {
            public function __construct(private string $root, private PhpErrorLogHttpTest $test)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->test->commands[] = (array) $cmd;
                $output = '';
                if (is_array($cmd) && in_array('setpriv', $cmd, true)) {
                    $content = file((string) $cmd[11], FILE_IGNORE_NEW_LINES);
                    $output = implode("\n", array_slice($content === false ? [] : $content, -(int) $cmd[12]));
                }

                return new class ($output) extends Process {
                    public function __construct(private string $output)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function getOutput(): string
                    {
                        return $this->output;
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->commands[] = (array) $cmd;
                if (is_array($cmd) && str_contains(implode(' ', $cmd), "'logs'")) {
                    return "app-1  | [11-Oct-2026 10:00:01 UTC] PHP Warning:  from the app\n";
                }

                return '';
            }
        });

        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_whole_log_is_returned_oldest_first(): void
    {
        $this->account();
        $this->writeLog("first\nsecond\nthird\n");

        $response = $this->getJson('/api/projects/alice/php/error-log');

        $response->assertOk()->assertExactJson([
            'data' => [
                'lines' => ['first', 'second', 'third'],
                'file' => 'php_error.log',
                'size' => filesize($this->logPath()),
                'mtime' => filemtime($this->logPath()),
                'truncated' => false,
            ],
        ]);
        $this->assertSame(1, count($this->commands));
        $this->assertAccountTail(0, $this->logPath(), '201');
    }

    public function test_lines_returns_only_the_last_ones_and_reports_truncation(): void
    {
        $this->account();
        $this->writeLog("first\nsecond\nthird\n");

        $response = $this->getJson('/api/projects/alice/php/error-log?lines=1');

        $response->assertOk();
        $response->assertJsonPath('data.lines', ['third']);
        $response->assertJsonPath('data.truncated', true);
        $this->assertSame(1, count($this->commands));
        $this->assertAccountTail(0, $this->logPath(), '2');
    }

    public function test_since_drops_lines_older_than_it_but_keeps_a_stack_frame(): void
    {
        $this->account();
        $this->writeLog(
            "[11-Oct-2026 10:00:01 UTC] PHP Warning:  old\n"
            . "[11-Oct-2026 12:00:01 UTC] PHP Warning:  new\n"
            . "#0 /app/index.php(1): foo()\n"
        );

        $response = $this->getJson('/api/projects/alice/php/error-log?since=2026-10-11T11:00:00Z');

        $response->assertOk();
        $response->assertJsonPath('data.lines', [
            '[11-Oct-2026 12:00:01 UTC] PHP Warning:  new',
            '#0 /app/index.php(1): foo()',
        ]);
    }

    /** The same `since` as container_service_logs: a duration counts back from now. */
    public function test_since_takes_a_duration_back_from_now(): void
    {
        Carbon::setTestNow('2026-10-11T12:30:00Z');
        $this->account();
        $this->writeLog(
            "[11-Oct-2026 10:00:01 UTC] PHP Warning:  old\n"
            . "[11-Oct-2026 12:00:01 UTC] PHP Warning:  new\n"
        );

        $this->getJson('/api/projects/alice/php/error-log?since=1h')
            ->assertOk()
            ->assertJsonPath('data.lines', ['[11-Oct-2026 12:00:01 UTC] PHP Warning:  new']);
    }

    public function test_a_container_project_passes_a_duration_on_as_the_time_it_names(): void
    {
        Carbon::setTestNow('2026-10-11T12:30:00Z');
        $this->account('dind');
        $this->dindCompose("services:\n  app:\n    image: test\n");

        $this->getJson('/api/projects/alice/php/error-log?since=1h30m')->assertOk();

        $this->assertStringContainsString("'--since=2026-10-11T11:00:00+00:00'", implode(' ', end($this->commands)));
    }

    public function test_a_since_that_is_neither_a_date_nor_a_duration_says_what_is_taken(): void
    {
        $this->account();

        $this->getJson('/api/projects/alice/php/error-log?since=yesterday')
            ->assertStatus(422)
            ->assertJsonPath('errors.since.0', 'The since must be an RFC 3339 time (2026-10-03T12:00:00Z) or a duration back from now (10m, 2h, 1h30m).');
    }

    public function test_a_project_without_a_log_file_answers_the_empty_shape(): void
    {
        $this->account();

        $this->getJson('/api/projects/alice/php/error-log')
            ->assertOk()
            ->assertExactJson([
                'data' => ['lines' => [], 'file' => null, 'size' => 0, 'mtime' => null, 'truncated' => false],
            ]);
        $this->assertSame([], $this->commands);
    }

    public function test_a_symlinked_log_is_never_read(): void
    {
        $this->account();
        $outside = $this->root . '/outside-secret.txt';
        file_put_contents($outside, "root secret\n");
        symlink($outside, $this->logPath());

        $this->getJson('/api/projects/alice/php/error-log')
            ->assertOk()
            ->assertExactJson([
                'data' => ['lines' => [], 'file' => null, 'size' => 0, 'mtime' => null, 'truncated' => false],
            ]);
        $this->assertSame([], $this->commands);
    }

    public function test_a_hard_linked_log_is_never_read(): void
    {
        $this->account();
        $this->writeLog("secret elsewhere\n");
        link($this->logPath(), $this->root . '/engine/users/alice/other.log');

        $this->getJson('/api/projects/alice/php/error-log')
            ->assertOk()
            ->assertJsonPath('data.file', null);
        $this->assertSame([], $this->commands);
    }

    public function test_a_log_owned_by_another_user_is_never_read(): void
    {
        $this->account();
        $this->writeLog("someone else's\n");
        chown($this->logPath(), posix_getuid());

        $this->getJson('/api/projects/alice/php/error-log')
            ->assertOk()
            ->assertJsonPath('data.file', null);
        $this->assertSame([], $this->commands);
    }

    public function test_a_line_count_outside_the_window_is_refused_before_anything_runs(): void
    {
        $this->account();

        foreach (['0', '-1', '5001', 'ten'] as $lines) {
            $this->getJson('/api/projects/alice/php/error-log?lines=' . urlencode($lines))
                ->assertStatus(422)
                ->assertJsonValidationErrors('lines');
        }
        $this->getJson('/api/projects/alice/php/error-log?since=yesterday')
            ->assertStatus(422)
            ->assertJsonValidationErrors('since');

        $this->assertSame([], $this->commands);
    }

    public function test_a_domain_must_belong_to_the_project(): void
    {
        $this->account();
        $this->writeLog("first\n");
        $this->domain('a.example');

        $this->getJson('/api/projects/alice/php/error-log?domain=a.example')
            ->assertOk()
            ->assertJsonPath('data.lines', ['first']);
        $this->getJson('/api/projects/alice/php/error-log?domain=missing.example')
            ->assertNotFound()
            ->assertJsonPath('message', 'Domain not found');
    }

    public function test_a_container_project_proxies_the_application_service_logs(): void
    {
        $this->account('dind');
        $this->dindCompose("services:\n  app:\n    image: test\n");

        $response = $this->getJson('/api/projects/alice/php/error-log?lines=50');

        $response->assertOk();
        $response->assertJsonPath('data.file', null);
        $response->assertJsonPath('data.mtime', null);
        $response->assertJsonPath('data.truncated', false);
        $response->assertJsonPath('data.lines', ['app-1  | [11-Oct-2026 10:00:01 UTC] PHP Warning:  from the app']);
        $this->assertStringContainsString("'logs' '--tail=50'", implode(' ', end($this->commands)));
    }

    public function test_the_dind_logs_come_from_the_service_publishing_the_app_port(): void
    {
        $this->account('dind');
        $user = User::findByUsername('alice');
        $user->setAppPort(8080);
        $user->save();
        $this->dindCompose("services:\n  web:\n    image: test\n    ports:\n      - \"8080:80\"\n  app:\n    image: test\n");

        $this->getJson('/api/projects/alice/php/error-log?lines=5')->assertOk();

        $this->assertStringContainsString("'logs' '--tail=5' '--no-color' '--' 'web'", implode(' ', end($this->commands)));
    }

    public function test_a_dind_project_without_a_recorded_port_falls_back_to_the_first_service(): void
    {
        $this->account('dind');
        $this->dindCompose("services:\n  web:\n    image: test\n  db:\n    image: test\n");

        $this->getJson('/api/projects/alice/php/error-log?lines=5')->assertOk();

        $this->assertStringContainsString("'logs' '--tail=5' '--no-color' '--' 'web'", implode(' ', end($this->commands)));
    }

    private function dindCompose(string $yaml): void
    {
        mkdir($this->root . '/home/alice/project', 0777, true);
        file_put_contents($this->root . '/home/alice/project/docker-compose.panelalpha.yml', $yaml);
    }

    private function account(string $template = 'default'): User
    {
        /** @var User $user */
        $user = User::make([
            'username' => 'alice',
            'domain' => 'alice.test',
            'details' => ['template' => $template, 'UID' => 1001, 'GID' => 1001],
        ]);
        $user->save();

        return $user;
    }

    private function domain(string $domain): void
    {
        $user = User::findByUsername('alice');
        $model = new \App\Models\Domain();
        $model->user_id = $user->id;
        $model->domain = $domain;
        $model->type = 'main';
        $model->setDetails(['document_root' => "/{$domain}/public_html"]);
        $model->save();
    }

    private function logPath(): string
    {
        return $this->root . '/engine/users/alice/log/php/php_error.log';
    }

    private function writeLog(string $contents): void
    {
        file_put_contents($this->logPath(), $contents);
        chown($this->logPath(), 1001);
        chgrp($this->logPath(), 1001);
    }

    /** The account-read argv: setpriv, then `sh -c <script> sh <path> <lines>`. */
    private function assertAccountTail(int $index, string $path, string $lines): void
    {
        $command = $this->commands[$index];
        $this->assertSame(['sudo', 'setpriv', '--reuid', '1001', '--regid', '1001', '--clear-groups'], array_slice($command, 0, 7));
        $this->assertSame(['sh', '-c'], array_slice($command, 7, 2));
        $this->assertSame('sh', $command[10]);
        $this->assertSame($path, $command[11]);
        $this->assertSame($lines, $command[12]);
    }
}
