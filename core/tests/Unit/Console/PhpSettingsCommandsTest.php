<?php

namespace Tests\Unit\Console;

use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\FpmStack;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Services\Webserver;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The five PHP-settings commands: what they print, and the exit code or the
 * failure artisan prints, for success, not-found and validation failures.
 */
class PhpSettingsCommandsTest extends TestCase
{
    use RendersCommandFailures;

    private string $tmpRoot;

    private System $system;

    private int $routed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-php-cli-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/engine/templates/user/default/project/php', 0777, true);
        file_put_contents(
            $this->tmpRoot . '/engine/templates/user/default/project/php/versions-available',
            "8.3\n8.2\n"
        );

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        (require base_path('database/migrations/2022_09_06_160855_create_domains_table.php'))->up();
        (require base_path('database/migrations/2022_09_05_105857_create_admins_table.php'))->up();

        $this->system = $this->fakeSystem($this->tmpRoot);
        $this->app->instance(System::class, $this->system);
        $this->setCurrentWebserver('nginx');

        Event::listen(Routing::class, function (): void {
            $this->routed++;
        });
    }

    protected function tearDown(): void
    {
        $this->setCurrentWebserver(null);
        Schema::dropIfExists('admins');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    // domain:php-directives

    public function test_domain_directives_print_the_map(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\nupload_max_filesize=64M\n");

        $this->assertCommand(0, "{\"memory_limit\":\"256M\",\"upload_max_filesize\":\"64M\"}\n", 'domain:php-directives', [
            'domain' => ' A.Example ',
        ]);
    }

    public function test_domain_directives_without_a_file_print_an_empty_object(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');

        $this->assertCommand(0, "{}\n", 'domain:php-directives', ['domain' => 'a.example']);
    }

    public function test_domain_directives_of_an_unknown_domain(): void
    {
        $this->account();
        $this->domain('a.example', ['www.a.example']);

        $this->assertFails("Domain 'missing.example' not found.\n", 'domain:php-directives', ['domain' => 'missing.example']);
        $this->assertFails("Domain 'www.a.example' not found.\n", 'domain:php-directives', ['domain' => 'www.a.example']);
    }

    public function test_domain_directives_need_a_name(): void
    {
        $this->assertCommand(1, "Domain name is required.\n", 'domain:php-directives', ['domain' => ' ']);
    }

    public function test_unreadable_domain_directives_are_a_validation_error(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=\"unclosed\n");

        $this->assertFails("Directive file is not valid INI.\n", 'domain:php-directives', ['domain' => 'a.example']);
    }

    public function test_an_unexpected_fault_is_left_to_artisan(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\n");
        chmod($this->userIni('a.example'), 0);

        $this->expectExceptionMessage('Permission denied');
        Artisan::call('domain:php-directives', ['domain' => 'a.example']);
    }

    public function test_directives_that_cannot_be_json_encoded_are_not_printed_as_empty(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=\"\xff\"\n");

        $this->expectException(\JsonException::class);
        Artisan::call('project:php-directives', ['username' => 'alice', 'version' => '8.2']);
    }

    // domain:php-directives:set

    public function test_domain_directives_set_writes_the_file(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');

        $this->assertCommand(0, '', 'domain:php-directives:set', [
            'domain' => 'a.example',
            '--settings' => '{"memory_limit":"256M"}',
        ]);
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->userIni('a.example')));
    }

    public function test_domain_directives_clear_removes_the_file(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\n");

        $this->assertCommand(0, '', 'domain:php-directives:set', ['domain' => 'a.example', '--clear' => true]);
        $this->assertFileDoesNotExist($this->userIni('a.example'));
    }

    public function test_domain_directives_set_on_an_unknown_domain(): void
    {
        $this->assertFails("Domain 'missing.example' not found.\n", 'domain:php-directives:set', [
            'domain' => 'missing.example',
            '--settings' => '{"memory_limit":"256M"}',
        ]);
    }

    public function test_domain_directives_set_rejects_invalid_ini_and_a_missing_document_root(): void
    {
        $this->account();
        $this->domain('a.example');

        $this->assertFails("Document root does not exist.\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--settings' => '{"memory_limit":"256M"}',
        ]);

        $this->makeDocumentRoot('a.example');
        $this->assertFails("Invalid INI.\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--settings' => '{"memory_limit":"256M; dropped"}',
        ]);
        $this->assertFileDoesNotExist($this->userIni('a.example'));
    }

    public function test_a_docker_failure_prints_its_message(): void
    {
        $this->account();
        $this->domain('a.example');
        $this->makeDocumentRoot('a.example');
        file_put_contents($this->userIni('a.example'), "memory_limit=256M\n");
        $this->system->failWith = 'Error response from daemon: No such container: alice';

        $this->assertFails("Error response from daemon: No such container: alice\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--clear' => true,
        ]);
        $this->assertFileExists($this->userIni('a.example'));
    }

    public function test_domain_directives_set_checks_its_options(): void
    {
        $this->assertCommand(1, "Pass either --settings or --clear, not both.\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--settings' => '{}', '--clear' => true,
        ]);
        $this->assertCommand(1, "Pass --settings or --clear.\n", 'domain:php-directives:set', ['domain' => 'a.example']);
        $this->assertCommand(1, "--settings must be a JSON object.\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--settings' => '["x"]',
        ]);
        $this->assertCommand(1, "--settings values must be strings.\n", 'domain:php-directives:set', [
            'domain' => 'a.example', '--settings' => '{"a":1}',
        ]);
    }

    // domain:php-version

    public function test_domain_php_version_prints_the_stored_version(): void
    {
        $this->account();
        $this->domain('a.example', [], ['php_version' => '8.2']);

        $this->assertCommand(0, "8.2\n", 'domain:php-version', ['domain' => 'a.example']);
    }

    public function test_domain_php_version_of_an_unknown_domain(): void
    {
        $this->assertFails("Domain 'missing.example' not found.\n", 'domain:php-version', ['domain' => 'missing.example']);
        $this->assertFails("Domain 'missing.example' not found.\n", 'domain:php-version', [
            'domain' => 'missing.example', 'version' => '8.2',
        ]);
    }

    public function test_domain_php_version_rejects_a_version_that_is_not_installed(): void
    {
        $this->account();
        $this->domain('a.example', [], ['php_version' => '8.2']);

        $this->assertFails("Invalid value\n", 'domain:php-version', ['domain' => 'a.example', 'version' => '5.6']);
        $this->assertSame('8.2', Domain::findByName('a.example')?->getPhpVersion());
    }

    // project:php-directives

    public function test_project_directives_print_the_map(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        $this->assertCommand(0, "{\"memory_limit\":\"256M\"}\n", 'project:php-directives', [
            'username' => 'alice', 'version' => '8.2',
        ]);
        $this->assertCommand(0, "{}\n", 'project:php-directives', ['username' => 'alice', 'version' => '8.3']);
    }

    public function test_project_directives_of_an_unknown_project(): void
    {
        $this->assertFails("Project 'nobody' not found.\n", 'project:php-directives', [
            'username' => 'nobody', 'version' => '8.2',
        ]);
    }

    public function test_project_directives_reject_an_unknown_version_and_a_dind_project(): void
    {
        $this->account();
        $this->assertFails("Invalid value\n", 'project:php-directives', ['username' => 'alice', 'version' => '5.6']);

        $this->account('dind', 'bob');
        $this->assertFails($this->dindRefusal(), 'project:php-directives', ['username' => 'bob', 'version' => '8.2']);
    }

    public function test_project_directives_need_both_arguments(): void
    {
        $this->assertCommand(1, "Username and PHP version are required.\n", 'project:php-directives', [
            'username' => 'alice', 'version' => ' ',
        ]);
    }

    // project:php-directives:set

    public function test_project_directives_set_writes_only_that_version(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "display_errors=0\n");
        $this->writeAccountIni('8.3', "display_errors=1\n");

        $this->assertCommand(0, '', 'project:php-directives:set', [
            'username' => 'alice', 'version' => '8.2', '--settings' => '{"memory_limit":"256M"}',
        ]);
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame("display_errors=1\n", file_get_contents($this->accountIni('8.3')));
        $this->assertSame(1, $this->restartCount('8.2'));

        $this->assertCommand(0, '', 'project:php-directives:set', [
            'username' => 'alice', 'version' => '8.2', '--clear' => true,
        ]);
        $this->assertSame('', file_get_contents($this->accountIni('8.2')));
    }

    public function test_project_directives_set_on_an_unknown_project(): void
    {
        $this->assertFails("Project 'nobody' not found.\n", 'project:php-directives:set', [
            'username' => 'nobody', 'version' => '8.2', '--clear' => true,
        ]);
    }

    public function test_project_directives_set_rejects_bad_input(): void
    {
        $this->account();
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        $this->assertFails("Invalid value\n", 'project:php-directives:set', [
            'username' => 'alice', 'version' => '5.6', '--settings' => '{"memory_limit":"64M"}',
        ]);
        $this->assertFails("Could not set php.ini directives. Invalid INI.\n", 'project:php-directives:set', [
            'username' => 'alice', 'version' => '8.2', '--settings' => '{"memory_limit":"256M; no"}',
        ]);
        $this->assertCommand(1, "--settings values must be strings.\n", 'project:php-directives:set', [
            'username' => 'alice', 'version' => '8.2', '--settings' => '{"a":[]}',
        ]);
        $this->assertSame("memory_limit=256M\n", file_get_contents($this->accountIni('8.2')));
        $this->assertSame(0, $this->restartCount('8.2'));

        $this->account('dind', 'bob');
        $this->assertFails($this->dindRefusal(), 'project:php-directives:set', [
            'username' => 'bob', 'version' => '8.2', '--clear' => true,
        ]);
    }

    public function test_no_command_goes_through_the_http_router(): void
    {
        $this->account();
        $this->domain('a.example', [], ['php_version' => '8.2']);
        $this->makeDocumentRoot('a.example');
        $this->writeAccountIni('8.2', "memory_limit=256M\n");

        Artisan::call('domain:php-directives', ['domain' => 'a.example']);
        Artisan::call('domain:php-directives:set', ['domain' => 'a.example', '--clear' => true]);
        Artisan::call('domain:php-version', ['domain' => 'a.example']);
        $this->failureOf('domain:php-version', ['domain' => 'a.example', 'version' => '5.6']);
        Artisan::call('project:php-directives', ['username' => 'alice', 'version' => '8.2']);
        Artisan::call('project:php-directives:set', ['username' => 'alice', 'version' => '8.2', '--clear' => true]);

        $this->assertSame(0, $this->routed, 'a command dispatched an HTTP route');
        $this->assertNull(Auth::user(), 'a command logged someone in');
        $this->assertSame(0, DB::table('admins')->count());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertCommand(int $exitCode, string $output, string $command, array $arguments): void
    {
        $this->assertSame($exitCode, Artisan::call($command, $arguments));
        $this->assertSame($output, Artisan::output());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertFails(string $output, string $command, array $arguments): void
    {
        $this->assertSame($output, $this->failureOf($command, $arguments));
    }

    private function dindRefusal(): string
    {
        return 'Custom PHP INI settings apply only to PHP hosting projects. '
            . "A dind project runs PHP from its own image; set php.ini there.\n";
    }

    private function account(string $template = 'default', string $username = 'alice'): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username . '.test';
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
     * @param array<string, mixed> $details
     */
    private function domain(string $name, array $aliases = [], array $details = []): Domain
    {
        $user = User::findByUsername('alice');
        $this->assertNotNull($user);

        $domain = new Domain();
        $domain->user_id = $user->id;
        $domain->domain = $name;
        $domain->type = 'main';
        $domain->setDetails([
            'document_root' => '/' . $name . '/public_html',
            'aliases' => $aliases,
        ] + $details);
        $domain->save();

        return $domain;
    }

    private function makeDocumentRoot(string $domain): void
    {
        mkdir($this->tmpRoot . '/home/alice/' . $domain . '/public_html', 0777, true);
    }

    private function userIni(string $domain): string
    {
        return $this->tmpRoot . '/home/alice/' . $domain . '/public_html/.user.ini';
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

    private function restartCount(string $version): int
    {
        // The account has no services/, so it is on the runner.
        $script = FpmStack::restartScript(
            new RunnerServiceManager(new PhpHosting(new ProjectAggregate(new System(), new User()))),
            $version
        );
        /** @var System&object{processJournal: list<array<int, string>>} $system */
        $system = $this->system;
        $count = 0;
        foreach ($system->processJournal as $command) {
            if (in_array($script, $command, true)) {
                $count++;
            }
        }

        return $count;
    }

    private function fakeSystem(string $root): System
    {
        return new class ($root) extends System {
            /** @var list<array<int, string>> */
            public array $processJournal = [];

            public ?string $failWith = null;

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
                // A failing command changes nothing.
                $applies = $this->failWith === null && is_array($cmd);
                if ($applies && ($cmd[1] ?? null) === 'cp' && isset($cmd[2], $cmd[3])) {
                    copy($cmd[2], $cmd[3]);
                }
                if ($applies && ($cmd[1] ?? null) === 'rm') {
                    $path = $cmd[array_key_last($cmd)];
                    if (is_string($path) && is_file($path)) {
                        unlink($path);
                    }
                }

                return new class ($this->failWith) extends Process {
                    public function __construct(private ?string $error)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->error === null ? 0 : 1;
                    }

                    public function isSuccessful(): bool
                    {
                        return $this->error === null;
                    }

                    public function getOutput(): string
                    {
                        return '';
                    }

                    public function getErrorOutput(): string
                    {
                        return $this->error ?? '';
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
