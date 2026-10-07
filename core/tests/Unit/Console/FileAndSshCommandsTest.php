<?php

namespace Tests\Unit\Console;

use App\Exceptions\NotFoundException;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\EnginePaths;
use App\System\Project\FileManager;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * What the file, log and ssh commands print and return, pinned end to end:
 * the engine code runs for real and only `sudo` is a shim, which records
 * every call and answers with canned output unless told to run the command.
 *
 * The tests that need real files under /home and the engine dir skip unless
 * both are writable; in the composer:2 container, bind-mount scratch dirs
 * with `-v <dir>:/home -v <dir>:/opt/panelalpha/shared-hosting`.
 */
class FileAndSshCommandsTest extends TestCase
{
    private const USER = 'clidemo';
    private const DOMAIN = 'clidemo.test';

    private string $shimDir;
    private string $shimLog;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach ([
            '2014_10_12_000000_create_users_table',
            '2022_09_05_105857_create_admins_table',
            '2022_09_06_160855_create_domains_table',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        $user = $this->makeUser(self::USER, 'dind');
        $domain = new Domain();
        $domain->domain = self::DOMAIN;
        $domain->user_id = $user->id;
        $domain->type = 'main';
        $domain->save();
        $this->makeUser('phpdemo', 'default');

        $this->shimDir = sys_get_temp_dir() . '/pa-cli-shim-' . bin2hex(random_bytes(4));
        mkdir($this->shimDir);
        $this->cleanup[] = $this->shimDir;
        $this->shimLog = $this->shimDir . '/calls.log';
        file_put_contents($this->shimDir . '/sudo', <<<'SH'
            #!/bin/sh
            if [ "$1" = setpriv ]; then shift 6; fi
            { for a in "$@"; do printf '%s\037' "$a"; done; printf '\n'; } >> "$SHIM_LOG"
            for c in $SHIM_EXEC; do
              if [ "$1" = "$c" ]; then exec "$@"; fi
            done
            printf '%s' "$SHIM_STDOUT"
            printf '%s' "$SHIM_STDERR" >&2
            exit "${SHIM_EXIT:-0}"
            SH);
        chmod($this->shimDir . '/sudo', 0755);

        $this->setEnv('PATH', $this->shimDir . ':' . (string) getenv('PATH'));
        $this->setEnv('SHIM_LOG', $this->shimLog);
        $this->setEnv('SHIM_EXEC', '');
        $this->setEnv('SHIM_STDOUT', '');
        $this->setEnv('SHIM_STDERR', '');
        $this->setEnv('SHIM_EXIT', '0');

        $this->setWebserver('nginx');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        foreach ($this->cleanup as $path) {
            exec('rm -rf ' . escapeshellarg($path));
        }
        $this->setWebserver(null);
        Schema::dropIfExists('domains');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    // --- project:file:chmod ---

    public function test_chmod_requires_path_and_mode(): void
    {
        $this->assertRun(1, "--path and --mode are required\n", 'project:file:chmod', ['project' => self::USER, '--path' => 'a']);
    }

    public function test_chmod_rejects_a_bad_mode_before_looking_up_the_project(): void
    {
        $this->assertFails(ValidationException::class, "The mode format is invalid.\n", 'project:file:chmod', [
            'project' => 'nope', '--path' => 'a', '--mode' => '999',
        ]);
        $this->assertSame([], $this->calls());
    }

    public function test_chmod_on_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:file:chmod', [
            'project' => 'nope', '--path' => 'a', '--mode' => '755',
        ]);
    }

    public function test_chmod_rejects_a_path_that_climbs_out_of_the_home(): void
    {
        $this->assertFails(ValidationException::class, "Invalid path\n", 'project:file:chmod', [
            'project' => self::USER, '--path' => '../etc', '--mode' => '755',
        ]);
        $this->assertSame([], $this->calls());
    }

    public function test_chmod_runs_chmod_as_the_account(): void
    {
        $this->assertRun(0, "Set mode 0755 on public_html/x.sh\n", 'project:file:chmod', [
            'project' => self::USER, '--path' => 'public_html/x.sh', '--mode' => '0755',
        ]);
        $this->assertSame([
            $this->confined('F:' . $this->home() . '/public_html/x.sh'),
            ['chmod', '0755', $this->home() . '/public_html/x.sh'],
        ], $this->calls());
    }

    public function test_chmod_prints_why_the_command_failed(): void
    {
        $this->setEnv('SHIM_EXIT', '1');
        $this->setEnv('SHIM_STDERR', 'denied');
        $this->assertFails(ValidationException::class, "denied (exit code 1)\n", 'project:file:chmod', [
            'project' => self::USER, '--path' => 'x', '--mode' => '644',
        ]);
    }

    // --- project:file:fetch ---

    public function test_fetch_requires_url_and_path(): void
    {
        $this->assertRun(1, "--url and --path are required\n", 'project:file:fetch', ['project' => self::USER, '--url' => 'https://x']);
    }

    public function test_fetch_rejects_a_non_http_url(): void
    {
        $this->assertFails(ValidationException::class, "The url format is invalid.\n", 'project:file:fetch', [
            'project' => self::USER, '--url' => 'ftp://example.com/a.zip', '--path' => 'dl',
        ]);
    }

    public function test_fetch_on_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:file:fetch', [
            'project' => 'nope', '--url' => 'https://example.com/a.zip', '--path' => 'dl',
        ]);
    }

    public function test_fetch_into_a_missing_directory_fails(): void
    {
        $this->setEnv('SHIM_STDOUT', "missing\n");
        $dir = 'no-such-dir-' . bin2hex(random_bytes(3));

        $this->assertFails(ValidationException::class, "Destination directory does not exist\n", 'project:file:fetch', [
            'project' => self::USER, '--url' => 'https://example.com/a.zip', '--path' => $dir,
        ]);
        $this->assertSame([
            $this->confined('F:' . $this->home() . "/{$dir}/a.zip"),
            $this->entryType($this->home() . "/{$dir}"),
        ], $this->calls());
    }

    public function test_fetch_downloads_with_curl_as_the_account(): void
    {
        $this->needsRealPaths();
        mkdir($this->home() . '/dl', 0777, true);
        $this->setEnv('SHIM_EXEC', 'sh');

        $this->assertRun(0, "Fetched https://example.com/a.zip?x=1 into dl\n", 'project:file:fetch', [
            'project' => self::USER, '--url' => 'https://example.com/a.zip?x=1', '--path' => 'dl',
        ]);
        $this->assertRun(0, "Fetched https://example.com/get into dl/\n", 'project:file:fetch', [
            'project' => self::USER, '--url' => 'https://example.com/get', '--path' => 'dl/', '--filename' => 'b.bin',
        ]);
        $this->assertSame([
            $this->confined('F:' . $this->home() . '/dl/a.zip'),
            $this->entryType($this->home() . '/dl'),
            ['curl', '-fSL', 'https://example.com/a.zip?x=1', '-o', $this->home() . '/dl/a.zip'],
            $this->confined('F:' . $this->home() . '/dl/b.bin'),
            $this->entryType($this->home() . '/dl'),
            ['curl', '-fSL', 'https://example.com/get', '-o', $this->home() . '/dl/b.bin'],
        ], $this->calls());
    }

    // --- project:file:move-contents ---

    public function test_move_contents_requires_source_and_dest(): void
    {
        $this->assertRun(1, "--source and --dest are required\n", 'project:file:move-contents', ['project' => self::USER, '--source' => 'a']);
    }

    public function test_move_contents_on_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:file:move-contents', [
            'project' => 'nope', '--source' => 'a', '--dest' => 'b',
        ]);
    }

    public function test_move_contents_into_a_missing_directory_fails(): void
    {
        $this->setEnv('SHIM_STDOUT', "missing\n");
        $this->assertFails(ValidationException::class, "Destination directory does not exist\n", 'project:file:move-contents', [
            'project' => self::USER, '--source' => 'a', '--dest' => 'no-such-dir-' . bin2hex(random_bytes(3)),
        ]);
    }

    public function test_move_contents_moves_with_or_without_clobbering(): void
    {
        $this->needsRealPaths();
        mkdir($this->home() . '/dst', 0777, true);
        $this->setEnv('SHIM_EXEC', 'sh');

        $this->assertRun(0, "Moved children of src into dst\n", 'project:file:move-contents', [
            'project' => self::USER, '--source' => 'src', '--dest' => 'dst',
        ]);
        $this->assertRun(0, "Moved children of src into dst\n", 'project:file:move-contents', [
            'project' => self::USER, '--source' => 'src', '--dest' => 'dst', '--override' => '0',
        ]);
        $find = fn (string $flag): array => [
            'find', $this->home() . '/src', '-mindepth', '1', '-maxdepth', '1',
            '-exec', 'mv', $flag, '-t', $this->home() . '/dst', '--', '{}', '+',
        ];
        $checks = [
            $this->confined('F:' . $this->home() . '/src', 'F:' . $this->home() . '/dst'),
            $this->entryType($this->home() . '/dst'),
        ];
        $this->assertSame([...$checks, $find('--force'), ...$checks, $find('--no-clobber')], $this->calls());
    }

    // --- project:file:download ---

    public function test_download_requires_a_path(): void
    {
        $this->assertRun(1, "--path is required\n", 'project:file:download', ['project' => self::USER]);
    }

    public function test_download_from_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:file:download', ['project' => 'nope', '--path' => 'a']);
    }

    public function test_download_of_a_missing_file_names_it(): void
    {
        $this->setEnv('SHIM_EXIT', '1');
        $this->assertFails(NotFoundException::class, "File 'a.txt' not found in project 'clidemo'.\n", 'project:file:download', ['project' => self::USER, '--path' => 'a.txt']);
        $this->assertSame([['test', '-f', $this->home() . '/a.txt']], $this->calls());
    }

    public function test_download_rejects_a_path_that_climbs_out_of_the_home(): void
    {
        $this->assertFails(ValidationException::class, "Invalid path\n", 'project:file:download', ['project' => self::USER, '--path' => '../x']);
    }

    public function test_download_streams_the_file_to_stdout_and_to_a_file(): void
    {
        $this->needsRealPaths();
        $this->setEnv('SHIM_EXEC', 'test php');
        mkdir($this->home() . '/d', 0777, true);
        $payload = random_bytes(200 * 1024);
        file_put_contents($this->home() . '/d/blob.bin', $payload);

        [$exit, $output, $raw] = $this->run_('project:file:download', ['project' => self::USER, '--path' => 'd/blob.bin']);
        $this->assertSame(0, $exit);
        $this->assertSame('', $output);
        $this->assertSame($payload, $raw);

        $out = $this->tempPath();
        [$exit, $output, $raw] = $this->run_('project:file:download', ['project' => self::USER, '--path' => 'd/blob.bin', '--out' => $out]);
        $this->assertSame(0, $exit);
        $this->assertSame("Saved to {$out} (204,800 bytes)\n", $output);
        $this->assertSame('', $raw);
        $this->assertSame($payload, file_get_contents($out));
    }

    public function test_download_to_an_unwritable_out_path_throws(): void
    {
        $this->needsRealPaths();
        $this->setEnv('SHIM_EXEC', 'test php');
        mkdir($this->home(), 0777, true);
        file_put_contents($this->home() . '/a.txt', 'x');
        $out = '/nonexistent-dir/out.bin';

        // The "Could not open" branch is unreachable: the framework turns
        // fopen's warning into an exception first.
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage("fopen({$out}): Failed to open stream");
        $this->run_('project:file:download', ['project' => self::USER, '--path' => 'a.txt', '--out' => $out]);
    }

    // --- project:file:upload ---

    public function test_upload_requires_a_path_and_an_existing_file(): void
    {
        $this->assertRun(1, "--path is required (the destination directory inside the project)\n", 'project:file:upload', [
            'project' => self::USER, 'file' => __FILE__,
        ]);
        $this->assertRun(1, "Local file not found: /no/such/file\n", 'project:file:upload', [
            'project' => self::USER, 'file' => '/no/such/file', '--path' => 'x',
        ]);
    }

    public function test_upload_to_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:file:upload', [
            'project' => 'nope', 'file' => __FILE__, '--path' => 'x',
        ]);
    }

    public function test_upload_rejects_a_path_that_climbs_out_of_the_home(): void
    {
        $this->assertFails(ValidationException::class, "Invalid path\n", 'project:file:upload', [
            'project' => self::USER, 'file' => __FILE__, '--path' => '../x',
        ]);
    }

    public function test_upload_writes_the_file_as_the_account(): void
    {
        $local = $this->tempPath();
        file_put_contents($local, 'payload');

        $this->assertRun(0, sprintf("Uploaded %s to up/%s\n", basename($local), basename($local)), 'project:file:upload', [
            'project' => self::USER, 'file' => $local, '--path' => 'up/',
        ]);
        $this->assertSame([
            $this->confined('F:' . $this->home() . '/up/' . basename($local)),
            [
                'sh', '-c', 'mkdir -p -- "$(dirname -- "$2")" && cat -- "$1" > "$2" && chmod 644 -- "$2"',
                'sh', $local, $this->home() . '/up/' . basename($local),
            ],
        ], $this->calls());
    }

    public function test_upload_prints_why_the_write_failed(): void
    {
        $this->setEnv('SHIM_EXIT', '2');
        $this->setEnv('SHIM_STDOUT', 'no space');
        $this->assertFails(ValidationException::class, "no space (exit code 2)\n", 'project:file:upload', [
            'project' => self::USER, 'file' => __FILE__, '--path' => 'x',
        ]);
    }

    public function test_upload_lands_the_bytes_in_the_project(): void
    {
        $this->needsRealPaths();
        $this->setEnv('SHIM_EXEC', 'sh');
        $local = $this->tempPath();
        file_put_contents($local, 'real bytes');

        $this->assertRun(0, sprintf("Uploaded %s to in/%s\n", basename($local), basename($local)), 'project:file:upload', [
            'project' => self::USER, 'file' => $local, '--path' => 'in',
        ]);
        $this->assertSame('real bytes', file_get_contents($this->home() . '/in/' . basename($local)));
    }

    // --- project:domain:log ---

    public function test_domain_log_names_the_unknown_project_or_domain(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:domain:log', ['project' => 'nope', 'domain' => self::DOMAIN]);
        $notFound = "Domain 'other.test' not found for project 'clidemo'.\n";
        $this->assertFails(NotFoundException::class, $notFound, 'project:domain:log', ['project' => self::USER, 'domain' => 'other.test']);
        $this->assertFails(NotFoundException::class, $notFound, 'project:domain:log', [
            'project' => self::USER, 'domain' => 'other.test', 'filename' => 'access.log',
        ]);
    }

    public function test_domain_log_list_across_webservers_with_no_logs_is_empty(): void
    {
        $this->assertRun(0, '{"data":[]}', 'project:domain:log', [
            'project' => self::USER, 'domain' => self::DOMAIN, '--all-webservers' => true,
        ]);
        $this->assertFails(NotFoundException::class, "Log file 'access.log' not found for domain 'clidemo.test'.\n", 'project:domain:log', [
            'project' => self::USER, 'domain' => self::DOMAIN, 'filename' => 'access.log', '--all-webservers' => true,
        ]);
    }

    public function test_domain_log_list_without_a_log_dir_is_left_to_artisan_to_report(): void
    {
        $this->needsMissingLogDir();
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('scandir(' . EnginePaths::ENGINE_DIR . '/webserver-logs/nginx/' . self::DOMAIN . ')');
        $this->run_('project:domain:log', ['project' => self::USER, 'domain' => self::DOMAIN]);
    }

    public function test_domain_log_lists_and_downloads(): void
    {
        $this->needsRealPaths();
        $dir = EnginePaths::ENGINE_DIR . '/webserver-logs/nginx/' . self::DOMAIN;
        mkdir($dir, 0777, true);
        $this->cleanup[] = EnginePaths::ENGINE_DIR . '/webserver-logs';
        file_put_contents("{$dir}/access.log", "GET /\n");
        file_put_contents("{$dir}/other.log", 'ignored');
        touch("{$dir}/access.log", 1700000000);

        $this->assertRun(0, json_encode(['data' => [[
            'file' => 'access.log', 'path' => "{$dir}/access.log", 'mtime' => 1700000000, 'size' => 6,
        ]]]), 'project:domain:log', ['project' => self::USER, 'domain' => self::DOMAIN]);

        [$exit, $output, $raw] = $this->run_('project:domain:log', [
            'project' => self::USER, 'domain' => self::DOMAIN, 'filename' => 'access.log',
        ]);
        $this->assertSame([0, '', "GET /\n"], [$exit, $output, $raw]);

        $out = $this->tempPath();
        $this->assertRun(0, "Saved to {$out} (6 bytes)\n", 'project:domain:log', [
            'project' => self::USER, 'domain' => self::DOMAIN, 'filename' => 'access.log', '--out' => $out,
        ]);
        $this->assertSame("GET /\n", file_get_contents($out));

        $this->assertFails(NotFoundException::class, "Log file 'other.log' not found for domain 'clidemo.test'.\n", 'project:domain:log', [
            'project' => self::USER, 'domain' => self::DOMAIN, 'filename' => 'other.log',
        ]);
    }

    // --- modsec:log:show ---

    public function test_modsec_with_no_audit_logs(): void
    {
        $this->needsNoModsecDir();
        $this->assertRun(0, '{"data":[]}', 'modsec:log:show', []);
        $this->assertFails(NotFoundException::class, "Audit log file 'audit.log' not found.\n", 'modsec:log:show', ['filename' => 'audit.log']);
        $this->assertFails(NotFoundException::class, "Audit log file 'audit.log' not found.\n", 'modsec:log:show', ['filename' => 'audit.log', '--tail' => true]);
    }

    public function test_modsec_lists_downloads_and_tails(): void
    {
        $this->needsRealPaths();
        $dir = EnginePaths::ENGINE_DIR . '/logs/modsecurity';
        mkdir($dir, 0777, true);
        $this->cleanup[] = EnginePaths::ENGINE_DIR . '/logs';
        $body = "{\"a\":1}\nnot json\n{\"b\":{\"c\":\"x/y\"}}\n";
        file_put_contents("{$dir}/audit.log", $body);
        touch("{$dir}/audit.log", 1700000001);

        $this->assertRun(0, json_encode(['data' => [[
            'file' => 'audit.log', 'path' => "{$dir}/audit.log", 'mtime' => 1700000001, 'size' => strlen($body),
        ]]]), 'modsec:log:show', []);

        [$exit, $output, $raw] = $this->run_('modsec:log:show', ['filename' => 'audit.log']);
        $this->assertSame([0, '', $body], [$exit, $output, $raw]);

        $out = $this->tempPath();
        $this->assertRun(0, sprintf("Saved to %s (%d bytes)\n", $out, strlen($body)), 'modsec:log:show', [
            'filename' => 'audit.log', '--out' => $out,
        ]);
        $this->assertSame($body, file_get_contents($out));

        // Newest line first, unparseable lines dropped, slashes escaped.
        $this->assertRun(0, '{"data":[{"b":{"c":"x\/y"}},{"a":1}]}', 'modsec:log:show', ['filename' => 'audit.log', '--tail' => true]);
    }

    // --- project:ssh ---

    public function test_ssh_validates_before_looking_up_the_project(): void
    {
        $this->assertFails(ValidationException::class, "The cwd must not be greater than 4096 characters.\n", 'project:ssh', [
            'project' => 'nope', 'cmd' => 'ls', '--cwd' => str_repeat('a', 4097),
        ]);
        $this->assertFails(ValidationException::class, "The command field is required.\n", 'project:ssh', ['project' => 'nope', 'cmd' => '']);
    }

    public function test_ssh_prints_every_validation_message_on_its_own_line(): void
    {
        $this->assertFails(ValidationException::class, "The command field is required.\nThe cwd must not be greater than 4096 characters.\n", 'project:ssh', [
            'project' => 'nope', 'cmd' => '', '--cwd' => str_repeat('a', 4097),
        ]);
    }

    public function test_ssh_on_an_unknown_project_names_it(): void
    {
        $this->assertFails(NotFoundException::class, "Project 'nope' not found.\n", 'project:ssh', ['project' => 'nope', 'cmd' => 'ls']);
    }

    public function test_ssh_refuses_a_project_that_is_not_dind(): void
    {
        $this->assertFails(ValidationException::class, "Shell commands are only supported for dind projects.\n", 'project:ssh', [
            'project' => 'phpdemo', 'cmd' => 'ls',
        ]);
        $this->assertSame([], $this->calls());
    }

    public function test_ssh_prints_both_streams_and_returns_the_exit_code(): void
    {
        $this->setEnv('SHIM_STDOUT', "out line\n");
        $this->setEnv('SHIM_STDERR', "err line\n");
        $this->setEnv('SHIM_EXIT', '3');

        $this->assertRun(3, "out line\nerr line\n", 'project:ssh', [
            'project' => self::USER, 'cmd' => 'ls | wc -l', '--cwd' => '/home/clidemo/project', '--timeout' => '30',
        ]);
        $this->assertSame([[
            'docker', 'compose', '-f', (new System())->projectDirPath(self::USER) . '/docker-compose.yml', 'exec', '-T', 'dind',
            'su', '-s', '/bin/bash', '-l', self::USER, '-c', "cd '/home/clidemo/project' && ls | wc -l",
        ]], $this->calls());
    }

    public function test_ssh_json_prints_the_raw_result(): void
    {
        $this->setEnv('SHIM_STDOUT', "a/b \u{00e9}\n");
        $this->setEnv('SHIM_EXIT', '0');

        $this->assertRun(0, '{"stdout":"a\/b \u00e9\n","stderr":"","exit_code":0}' . "\n", 'project:ssh', [
            'project' => self::USER, 'cmd' => 'echo', '--json' => true,
        ]);
    }

    // --- no router ---

    /**
     * The commands call the engine directly: no route is matched, so no
     * throttle or token middleware runs, and no root admin row is created.
     */
    public function test_no_command_goes_through_the_router(): void
    {
        $matched = [];
        Event::listen(RouteMatched::class, function (RouteMatched $event) use (&$matched): void {
            $matched[] = $event->route->uri();
        });
        $local = $this->tempPath();
        file_put_contents($local, 'x');

        $this->runIgnoringFailure('project:file:chmod', ['project' => self::USER, '--path' => 'x', '--mode' => '644']);
        $this->runIgnoringFailure('project:file:fetch', ['project' => self::USER, '--url' => 'https://example.com/a', '--path' => 'no-dir']);
        $this->runIgnoringFailure('project:file:move-contents', ['project' => self::USER, '--source' => 'a', '--dest' => 'no-dir']);
        $this->runIgnoringFailure('project:file:upload', ['project' => self::USER, 'file' => $local, '--path' => 'x']);
        $this->runIgnoringFailure('project:domain:log', ['project' => self::USER, 'domain' => self::DOMAIN, '--all-webservers' => true]);
        $this->runIgnoringFailure('modsec:log:show', ['filename' => 'audit.log', '--tail' => true]);
        $this->runIgnoringFailure('project:ssh', ['project' => self::USER, 'cmd' => 'ls']);
        $this->setEnv('SHIM_EXIT', '1');
        $this->runIgnoringFailure('project:file:download', ['project' => self::USER, '--path' => 'x']);

        $this->assertSame([], $matched);
        $this->assertSame(0, Admin::query()->count());
        // chmod and upload each check the path first; fetch and move-contents
        // check it and then ask what the destination is.
        $this->assertCount(10, $this->calls(), 'the file commands, ssh and the download check reached the engine');
    }

    // --- helpers ---

    /** @param array<string, mixed> $args */
    private function assertRun(int $exit, string $output, string $command, array $args): void
    {
        [$gotExit, $gotOutput, $raw] = $this->run_($command, $args);
        $this->assertSame([$exit, $output, ''], [$gotExit, $gotOutput, $raw], "{$command} " . json_encode($args));
    }

    /**
     * The command throws an expected failure, which artisan prints as $printed.
     *
     * @param class-string<\Throwable> $class
     * @param array<string, mixed> $args
     */
    private function assertFails(string $class, string $printed, string $command, array $args): void
    {
        try {
            $this->run_($command, $args);
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, "{$command} " . json_encode($args));
            $output = new BufferedOutput();
            $this->app->make(ExceptionHandler::class)->renderForConsole($output, $e);
            $this->assertSame($printed, $output->fetch(), "{$command} " . json_encode($args));

            return;
        }
        $this->fail("{$command} " . json_encode($args) . " did not throw {$class}");
    }

    /** @param array<string, mixed> $args */
    private function runIgnoringFailure(string $command, array $args): void
    {
        try {
            $this->run_($command, $args);
        } catch (NotFoundException | ValidationException) {
        }
    }

    /**
     * @param array<string, mixed> $args
     * @return array{int, string, string} exit code, console output, bytes written straight to php://output
     */
    private function run_(string $command, array $args): array
    {
        ob_start();
        try {
            $exit = Artisan::call($command, $args);
        } finally {
            $raw = (string) ob_get_clean();
        }

        return [$exit, Artisan::output(), $raw];
    }

    /** @return list<list<string>> */
    private function calls(): array
    {
        if (!is_file($this->shimLog)) {
            return [];
        }
        $calls = [];
        foreach (explode("\n", rtrim((string) file_get_contents($this->shimLog), "\n")) as $line) {
            $calls[] = explode("\x1f", rtrim($line, "\x1f"));
        }

        return $calls;
    }

    /**
     * The file API's check that a path stays in the home, as the shim logs it.
     *
     * @return list<string>
     */
    private function confined(string ...$paths): array
    {
        return ['sh', '-c', FileManager::CONFINE_SCRIPT, 'sh', $this->home(), ...$paths];
    }

    /**
     * The file API asking, as the account, what is at a path.
     *
     * @return list<string>
     */
    private function entryType(string $path): array
    {
        return ['sh', '-c', FileManager::ENTRY_TYPE_SCRIPT, 'sh', $path, rtrim($path, '/')];
    }

    private function home(): string
    {
        return EnginePaths::HOMES_DIR . '/' . self::USER;
    }

    private function makeUser(string $username, string $template): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username . '.test';
        $user->password = 'secret';
        $user->setDetails(['template' => $template, 'UID' => 1001, 'GID' => 1001]);
        $user->save();

        return $user;
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    private function setWebserver(?string $webserver): void
    {
        $property = new \ReflectionProperty(Webserver::class, 'currentWebserver');
        $property->setValue(null, $webserver);
    }

    private function tempPath(): string
    {
        $path = $this->shimDir . '/tmp-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $path;

        return $path;
    }

    private function needsRealPaths(): void
    {
        if (!is_writable(EnginePaths::HOMES_DIR) || !is_writable(EnginePaths::ENGINE_DIR)) {
            $this->markTestSkipped('needs writable ' . EnginePaths::HOMES_DIR . ' and ' . EnginePaths::ENGINE_DIR);
        }
        $this->cleanup[] = $this->home();
    }

    private function needsMissingLogDir(): void
    {
        if (is_dir(EnginePaths::ENGINE_DIR . '/webserver-logs/nginx/' . self::DOMAIN)) {
            $this->markTestSkipped('a real log dir exists for ' . self::DOMAIN);
        }
    }

    private function needsNoModsecDir(): void
    {
        if (is_dir(EnginePaths::ENGINE_DIR . '/logs/modsecurity')) {
            $this->markTestSkipped('this host has real ModSecurity audit logs');
        }
    }
}
