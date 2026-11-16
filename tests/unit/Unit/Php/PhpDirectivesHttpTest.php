<?php

namespace Tests\Unit\Php;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class PhpDirectivesHttpTest extends TestCase
{
    private string $tmpRoot;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-php-directives-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/engine/templates/user/default/project/php', 0777, true);
        file_put_contents(
            $this->tmpRoot . '/engine/templates/user/default/project/php/versions-available',
            "8.3\n8.2\n"
        );
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->tmpRoot . '/engine', 'homes' => $this->tmpRoot . '/home']));

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        $users = require base_path('database/migrations/2014_10_12_000000_create_users_table.php');
        $domains = require base_path('database/migrations/2022_09_06_160855_create_domains_table.php');
        $users->up();
        $domains->up();

        $this->system = $this->fakeSystem($this->tmpRoot);
        $this->app->instance(System::class, $this->system);
        $this->withoutMiddleware(Authenticate::class);
        $this->setCurrentWebserver('nginx');
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        putenv('PA_ROOT_LOCAL_ROOTS');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_missing_domain_directive_file_reads_as_an_empty_object(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');

        $response = $this->getJson('/api/domains/a.example/php-directives');

        $response->assertOk();
        $this->assertSame('{"data":{}}', $response->getContent());
    }

    public function test_put_replaces_the_document_root_file_and_get_returns_it(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');

        $response = $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M', 'upload_max_filesize' => '64M'],
        ]);

        $response->assertNoContent();
        $path = $this->userIni('a.example');
        $this->assertSame(
            "memory_limit=256M\nupload_max_filesize=64M\n",
            file_get_contents($path)
        );
        $writes = array_values(array_filter($this->journal(), static fn (array $argv): bool => in_array($path, $argv, true)));
        $this->assertCount(1, $writes, 'one command writes the file, and no chown or chmod follows it');
        $this->assertSame(['bash', '-c', 'exec "${@:2}" < "$1"', 'bash'], array_slice($writes[0], 0, 4));
        $this->assertSame(Privileged::accountFiles(1001, 1001)->write($path, '644'), array_slice($writes[0], 5));

        $read = $this->getJson('/api/domains/a.example/php-directives');
        $read->assertOk();
        $read->assertExactJson([
            'data' => ['memory_limit' => '256M', 'upload_max_filesize' => '64M'],
        ]);
    }

    public function test_empty_settings_delete_the_file(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\n");

        $response = $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => [],
        ]);

        $response->assertNoContent();
        $this->assertFileDoesNotExist($this->userIni('a.example'));
        $again = $this->getJson('/api/domains/a.example/php-directives');
        $this->assertSame('{"data":{}}', $again->getContent());
    }

    public function test_invalid_ini_does_not_replace_the_file(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        $path = $this->userIni('a.example');
        file_put_contents($path, "memory_limit=256M\n");

        $response = $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => "256M; dropped"],
        ]);

        $response->assertStatus(422);
        $this->assertSame("memory_limit=256M\n", file_get_contents($path));
    }

    public function test_unreadable_ini_is_rejected_and_left_in_place(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        $path = $this->userIni('a.example');
        file_put_contents($path, "memory_limit=\"unclosed\n");

        $response = $this->getJson('/api/domains/a.example/php-directives');

        $response->assertStatus(422);
        $this->assertSame("memory_limit=\"unclosed\n", file_get_contents($path));
    }

    public function test_missing_document_root_is_rejected_without_creating_it(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');

        $response = $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M'],
        ]);

        $response->assertStatus(422);
        $this->assertDirectoryDoesNotExist(dirname($this->userIni('a.example')));
    }

    public function test_unknown_domain_and_alias_are_not_found(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html', ['www.a.example']);

        $this->getJson('/api/domains/missing.example/php-directives')->assertNotFound();
        $this->getJson('/api/domains/www.a.example/php-directives')->assertNotFound();
        $this->putJson('/api/domains/www.a.example/php-directives', [
            'settings' => ['memory_limit' => '256M'],
        ])->assertNotFound();
    }

    public function test_domains_that_share_a_document_root_share_directives(): void
    {
        $this->account();
        $this->domain('a.example', '/shared/public_html');
        $this->domain('b.example', '/shared/public_html');
        mkdir($this->home() . '/shared/public_html', 0777, true);

        $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '128M'],
        ])->assertNoContent();

        $this->getJson('/api/domains/b.example/php-directives')
            ->assertOk()
            ->assertExactJson(['data' => ['memory_limit' => '128M']]);
    }

    /** A save runs the cache check and nothing else: no restart, no signal outside the check. */
    public function test_put_runs_the_cache_check_and_restarts_no_handler(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html', [], '8.2');
        $this->makeDocumentRoot('a.example');

        $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M'],
        ])->assertNoContent();

        $this->assertSame("memory_limit=256M\n", file_get_contents($this->userIni('a.example')));
        $this->assertSame(1, $this->scriptCount(FpmStack::reloadScript('8.2')));
        $this->assertCount(2, $this->journal(), 'the file write and the cache check');
        $this->assertSame(0, $this->restartCount('8.2'));
    }

    public function test_clearing_domain_directives_runs_the_cache_check_and_restarts_no_handler(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html', [], '8.2');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\n");

        $this->putJson('/api/domains/a.example/php-directives', ['settings' => []])->assertNoContent();

        $this->assertFileDoesNotExist($this->userIni('a.example'));
        $this->assertSame(1, $this->scriptCount(FpmStack::reloadScript('8.2')));
        $this->assertCount(2, $this->journal(), 'the file removal and the cache check');
        $this->assertSame(0, $this->restartCount('8.2'));
    }

    public function test_a_refused_domain_map_signals_nothing(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html', [], '8.2');
        $this->makeDocumentRoot('a.example');

        $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M; no'],
        ])->assertStatus(422);

        $this->assertSame(0, $this->handlerSignals());
    }

    public function test_comments_and_sections_are_omitted_and_a_later_write_drops_them(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        $path = $this->userIni('a.example');
        $original = "memory_limit=256M\n; keep me\n[section]\nbar=1\n";
        file_put_contents($path, $original);

        $read = $this->getJson('/api/domains/a.example/php-directives');
        $read->assertOk();
        $read->assertExactJson(['data' => ['memory_limit' => '256M']]);
        $this->assertSame($original, file_get_contents($path));

        $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M'],
        ])->assertNoContent();
        $this->assertSame("memory_limit=256M\n", file_get_contents($path));
    }

    public function test_a_document_root_that_escapes_the_home_is_rejected(): void
    {
        $this->account();
        $this->domain('a.example', '/../../outside');

        $response = $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '256M'],
        ]);

        $response->assertStatus(422);
        $this->assertFileDoesNotExist($this->tmpRoot . '/outside/.user.ini');
    }

    public function test_account_directive_write_changes_only_the_named_version(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "display_errors=0\n");
        $this->writeAccountIni('8.3', "display_errors=1\n");

        $response = $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => ['memory_limit' => '256M'],
        ]);

        $response->assertNoContent();
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame("display_errors=1\n", file_get_contents($this->accountIni('8.3')));
        $this->assertSame(1, $this->restartCount('8.2'));
        $this->assertSame(0, $this->restartCount('8.3'));
    }

    public function test_empty_account_directives_clear_only_that_version_and_restart_it(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n");
        $this->writeAccountIni('8.3', "memory_limit=128M\n");

        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => [],
        ])->assertNoContent();

        $this->assertSame('', file_get_contents($this->accountIni('8.2')));
        $this->assertSame("memory_limit=128M\n", file_get_contents($this->accountIni('8.3')));
        $this->assertSame(1, $this->restartCount('8.2'));
        $this->assertSame(0, $this->restartCount('8.3'));
    }

    public function test_unknown_account_php_version_and_invalid_ini_do_not_write(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '5.6',
            'settings' => ['memory_limit' => '64M'],
        ])->assertStatus(422);
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));

        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => ['memory_limit' => "256M; no"],
        ])->assertStatus(422);
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame(0, $this->restartCount('8.2'));
    }

    public function test_account_ini_with_no_file_for_that_version_reads_as_an_empty_object(): void
    {
        $this->account();

        $response = $this->getJson('/api/projects/alice/php/custom-ini-settings?php_version=8.2');

        $response->assertOk();
        $this->assertSame('{"data":{}}', $response->getContent());
    }

    /** php.ini reads Off as "", On as "1" and E_ALL as a number; what was written is what reads back. */
    public function test_account_values_php_reads_as_something_else_are_written_and_read_as_given(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', '');
        $settings = self::valuesPhpNormalises();

        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => $settings,
        ])->assertNoContent();

        $this->assertSame(self::asIni($settings), file_get_contents($this->accountIni('8.2')));
        $this->getJson('/api/projects/alice/php/custom-ini-settings?php_version=8.2')
            ->assertOk()
            ->assertExactJson(['data' => $settings]);
    }

    public function test_domain_values_php_reads_as_something_else_are_written_and_read_as_given(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        $settings = self::valuesPhpNormalises();

        $this->putJson('/api/domains/a.example/php-directives', ['settings' => $settings])->assertNoContent();

        $this->assertSame(self::asIni($settings), file_get_contents($this->userIni('a.example')));
        $this->getJson('/api/domains/a.example/php-directives')
            ->assertOk()
            ->assertExactJson(['data' => $settings]);
    }

    /** The account file is read as the domain file is: a section is not part of the map. */
    public function test_account_ini_comments_and_sections_are_omitted(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n; keep me\n[PATH=/home/alice]\nbar=1\n");

        $this->getJson('/api/projects/alice/php/custom-ini-settings?php_version=8.2')
            ->assertOk()
            ->assertExactJson(['data' => ['memory_limit' => '256M']]);
    }

    /**
     * The tenant writes this file, and PHP's own reading would expand `${NAME}` from core's environment and a constant
     * name to its value; read as written, neither reaches the answer.
     */
    public function test_a_tenant_file_reads_back_names_not_cores_values(): void
    {
        $this->account();
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        putenv('PA_DIRECTIVES_PROBE=core-only-value');
        file_put_contents($this->userIni('a.example'), "error_log=\${PA_DIRECTIVES_PROBE}\nerror_reporting=PHP_VERSION\n");

        try {
            $response = $this->getJson('/api/domains/a.example/php-directives');
        } finally {
            putenv('PA_DIRECTIVES_PROBE');
        }

        $response->assertOk()->assertExactJson([
            'data' => ['error_log' => '${PA_DIRECTIVES_PROBE}', 'error_reporting' => 'PHP_VERSION'],
        ]);
        $this->assertStringNotContainsString('core-only-value', (string) $response->getContent());
    }

    /** A value PHP itself cannot read would stop it reading the whole file, so it is refused by its line. */
    public function test_a_value_php_cannot_read_is_refused_by_its_line(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => ['display_errors' => 'Off', 'error_log' => '/tmp/a"b'],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'settings' => 'Could not set php.ini directives. Invalid INI: PHP would not read "error_log=/tmp/a"b" as written.',
        ]);

        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame(0, $this->restartCount('8.2'));
    }

    /** @return array<string, string> */
    private static function valuesPhpNormalises(): array
    {
        return [
            'display_errors' => 'Off',
            'log_errors' => 'On',
            'html_errors' => 'false',
            'implicit_flush' => 'true',
            'expose_php' => 'no',
            'short_open_tag' => 'yes',
            'auto_prepend_file' => 'None',
            'error_reporting' => 'E_ALL & ~E_DEPRECATED',
            'memory_limit' => '256M',
        ];
    }

    /** @param  array<string, string>  $settings */
    private static function asIni(array $settings): string
    {
        $lines = '';
        foreach ($settings as $key => $value) {
            $lines .= "{$key}={$value}\n";
        }

        return $lines;
    }

    /** The domain-level tools change nothing a dind app reads, so they refuse too. */
    public function test_domain_directives_and_version_on_a_dind_project_are_422_and_write_nothing(): void
    {
        $this->account('dind');
        $this->domain('a.example', '/a.example/public_html');
        $this->makeDocumentRoot('a.example');
        $path = $this->userIni('a.example');
        file_put_contents($path, "memory_limit=256M\n");

        $this->getJson('/api/domains/a.example/php-directives')->assertStatus(422);
        $this->putJson('/api/domains/a.example/php-directives', [
            'settings' => ['memory_limit' => '64M'],
        ])->assertStatus(422);
        $this->putJson('/api/domains/a.example/php-version', ['version' => '8.2'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('version');

        $this->assertSame("memory_limit=256M\n", file_get_contents($path));
        // The domain was not switched: the request never got past the guard.
        $this->assertNull(Domain::findByName('a.example')->getDetails()['php_version'] ?? null);
    }

    public function test_account_ini_on_a_dind_project_is_a_422_and_writes_nothing(): void
    {
        $this->account('dind');
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        $this->getJson('/api/projects/alice/php/custom-ini-settings?php_version=8.2')
            ->assertStatus(422)
            ->assertJsonValidationErrors('project');
        $this->putJson('/api/projects/alice/php/custom-ini-settings', [
            'php_version' => '8.2',
            'settings' => ['memory_limit' => '64M'],
        ])->assertStatus(422)->assertJsonValidationErrors('project');

        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame(0, $this->restartCount('8.2'));
    }

    private function account(string $template = 'default'): User
    {
        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails([
            'template' => $template,
            'UID' => 1001,
            'GID' => 1001,
        ]);
        $user->save();

        return $user;
    }

    /**
     * @param array<int, string> $aliases
     */
    private function domain(string $name, string $documentRoot, array $aliases = [], ?string $phpVersion = null): Domain
    {
        $user = User::findByUsername('alice');
        $this->assertNotNull($user);

        $domain = new Domain();
        $domain->user_id = $user->id;
        $domain->domain = $name;
        $domain->type = 'main';
        $details = [
            'document_root' => $documentRoot,
            'aliases' => $aliases,
        ];
        if ($phpVersion !== null) {
            $details['php_version'] = $phpVersion;
        }
        $domain->setDetails($details);
        $domain->save();

        return $domain;
    }

    private function makeDocumentRoot(string $domain): void
    {
        mkdir($this->home() . '/' . $domain . '/public_html', 0777, true);
    }

    private function userIni(string $domain): string
    {
        return $this->home() . '/' . $domain . '/public_html/.user.ini';
    }

    private function home(): string
    {
        return $this->tmpRoot . '/home/alice';
    }

    private function writeAccountIni(string $version, string $contents): void
    {
        $dir = dirname($this->accountIni($version));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->accountIni($version), $contents);
    }

    private function accountIni(string $version): string
    {
        return $this->tmpRoot . "/engine/users/alice/php/{$version}/custom.ini";
    }

    /** Anything that would stop or replace the handler's processes: a restart entrypoint or a signal. */
    private function handlerSignals(): int
    {
        $count = 0;
        foreach ($this->journal() as $command) {
            $line = implode(' ', $command);
            foreach (['entrypoint-runner', 'pkill', 'pgrep', 'kill'] as $signal) {
                if (str_contains($line, $signal)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function restartCount(string $version): int
    {
        // The account has no services/, so it is on the runner.
        return $this->scriptCount(FpmStack::restartScript(
            new RunnerServiceManager(new PhpHosting(new ProjectAggregate(new System(), new User()))),
            $version
        ));
    }

    private function scriptCount(string $script): int
    {
        $count = 0;
        foreach ($this->journal() as $command) {
            if (in_array($script, $command, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array<int, string>>
     */
    private function journal(): array
    {
        /** @var System&object{processJournal: list<array<int, string>>} $system */
        $system = $this->system;

        return $system->processJournal;
    }

    private function fakeSystem(string $root): System
    {
        return new class ($root) extends System {
            /** @var list<array<int, string>> */
            public array $processJournal = [];

            public function __construct(private string $root)
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

            public function fileExists(string $path): bool
            {
                return is_file($path);
            }

            public function directoryExists(string $path): bool
            {
                return is_dir($path);
            }

            public function fileGetContents(string $path): string
            {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new \RuntimeException('Unreadable file.');
                }

                return $contents;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $args = is_array($cmd) ? $cmd : [$cmd];
                $this->processJournal[] = $args;
                $command = LocalRoot::plain(($args[0] ?? null) === 'bash' ? array_slice($args, 5) : $args);
                if (($args[0] ?? null) === 'bash' && ($command[0] ?? null) === 'sh' && isset($args[4])) {
                    copy($args[4], $command[4]);
                }
                if (($command[0] ?? null) === 'rm') {
                    $path = $command[array_key_last($command)];
                    if (is_string($path) && is_file($path)) {
                        unlink($path);
                    }
                }

                return new class () extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getOutput(): string
                    {
                        return '';
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }
                };
            }
        };
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        $property = (new ReflectionClass(Webserver::class))->getProperty('currentWebserver');
        $property->setAccessible(true);
        $property->setValue(null, $webserver);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
