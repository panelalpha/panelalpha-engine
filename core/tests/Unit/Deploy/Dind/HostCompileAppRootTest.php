<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The PHP frontend build reads the application's package.json, not the
 * checkout's. Group Office keeps package.json, composer.json and vendor/
 * under www/ (`app_root: www`); reading the root found no package.json and
 * skipped the build without a word, and every page then died on a missing
 * theme stylesheet.
 */
class HostCompileAppRootTest extends TestCase
{
    /** @param array<string, string> $files absolute account path => contents */
    private function system(array $files): System
    {
        return new class ($files) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this, $this->files) extends SystemFilesystem {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                // Only build containers: writing the runtime Composer manifest probes directories too.
                if (in_array('docker', $argv, true)) {
                    $this->commands[] = $argv;
                }
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    private function project(System $system): Dind
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder());

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        return $dind;
    }

    /**
     * @param array<string, string> $files
     * @return list<list<string>> the containers it ran
     */
    private function runForPhp(array $files, string $appRoot): array
    {
        $system = $this->system($files);
        // The Node image choice scans the real disk, where /home/acme is not.
        set_error_handler(static fn (int $no, string $message): bool => str_starts_with($message, 'scandir('), E_WARNING);
        try {
            $ran = (new HostCompile($this->project($system)))->runForPhp('/home/acme/project', [], $appRoot);
        } finally {
            restore_error_handler();
        }
        $this->assertTrue($ran, 'the frontend build was skipped');

        return $system->commands;
    }

    private static function workdir(array $argv): string
    {
        return $argv[array_search('-w', $argv, true) + 1];
    }

    public function test_the_build_reads_package_json_in_the_app_root_and_runs_there(): void
    {
        $commands = $this->runForPhp([
            '/home/acme/project/www/package.json' => '{"scripts":{"build":"sass themes:themes"}}',
            '/home/acme/project/www/composer.json' => '{"require":{"php":"^8.2"}}',
            '/home/acme/project/www/vendor/autoload.php' => '<?php',
        ], 'www');

        $this->assertCount(1, $commands, 'vendor/ is already resolved, so only the Node build runs');
        $this->assertSame('/app/www', self::workdir($commands[0]));
        $this->assertStringContainsString('npm run build', (string) end($commands[0]));
    }

    public function test_an_unresolved_app_root_gets_its_composer_pass_there_too(): void
    {
        $commands = $this->runForPhp([
            '/home/acme/project/www/package.json' => '{"scripts":{"build":"vite build"}}',
            '/home/acme/project/www/composer.json' => '{"require":{"php":"^8.2"}}',
        ], 'www');

        $this->assertCount(2, $commands);
        $this->assertContains('composer:2', $commands[0]);
        $this->assertSame('/app/www', self::workdir($commands[0]));
        $this->assertSame('/app/www', self::workdir($commands[1]));
    }

    /**
     * Upstream Group Office's www/package.json only declares workspaces, so
     * its recipe builds from a package.json at the checkout root. An app-root
     * package.json with no build script leaves that path as it was.
     */
    public function test_a_checkout_root_build_is_used_when_the_app_root_has_none(): void
    {
        $commands = $this->runForPhp([
            '/home/acme/project/package.json' => '{"scripts":{"build":"sh panelalpha-build.sh"}}',
            '/home/acme/project/www/package.json' => '{"private":true,"workspaces":["views/goui"]}',
            '/home/acme/project/www/composer.json' => '{"require":{"php":"^8.2"}}',
            '/home/acme/project/www/vendor/autoload.php' => '<?php',
        ], 'www');

        $this->assertCount(1, $commands);
        $this->assertSame('/app', self::workdir($commands[0]));
    }

    /** grocy: `vendor-dir: packages`, already resolved by the PHP build. */
    public function test_the_autoloader_is_looked_for_in_the_configured_vendor_dir(): void
    {
        $commands = $this->runForPhp([
            '/home/acme/project/package.json' => '{"scripts":{"build":"gulp build"}}',
            '/home/acme/project/composer.json' => '{"config":{"vendor-dir":"packages"}}',
            '/home/acme/project/packages/autoload.php' => '<?php',
        ], '');

        $this->assertCount(1, $commands, 'no second composer pass');
        $this->assertSame('/app', self::workdir($commands[0]));
    }
}
