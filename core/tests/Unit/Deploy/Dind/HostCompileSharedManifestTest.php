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
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/**
 * `runPhpBuild()` and the Composer-image pass (`runComposer()`, private)
 * both decide their runtime manifest through the same private
 * `runtimeComposerManifest()` -- architecturally guaranteed by there being
 * only one method that writes it, but nothing proved the two public-facing
 * passes actually land on the same file for the same project until now.
 *
 * `runComposer()` has no public caller left once a manifest already exists
 * (it is a `vendor/autoload.php`-gated step of `runForPhp()`), so it is
 * driven directly through reflection -- the same fixture, the same account,
 * the same private helper underneath, read twice.
 */
class HostCompileSharedManifestTest extends TestCase
{
    /**
     * A stubbed System that answers the given files, records every
     * `runProcess()` argv, and captures what a real `filePutContents()`
     * write (staged to a temp file, then `sudo cp`'d out) actually sent.
     *
     * @param array<string, string> $files absolute account path => contents
     * @param array<string, string> $copiedTo target path => contents, filled in by reference
     */
    private function stubbedSystem(array $files, array &$copiedTo): System
    {
        return new class ($files, $copiedTo) extends System {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo */
            public function __construct(private array $files, private array &$copiedTo)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class ($engine, $files) extends SystemFilesystem {
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
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? $cmd : [$cmd];
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };
    }

    private function stubbedProject(System $system): Dind
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
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        return $dind;
    }

    /**
     * A locked, php-pinned project: `composer.lock` is present and
     * `composer.json` carries a `php` requirement, which is exactly the
     * shape {@see PhpHostBuild::runtimeManifest()} always writes a manifest
     * for.
     */
    private function lockedPinnedComposerJson(): string
    {
        return (string) json_encode([
            'require' => ['php' => '^8.4', 'psr/log' => '^3'],
            'require-dev' => ['phpunit/phpunit' => '^9'],
        ]);
    }

    private function lockedPinnedComposerLock(): string
    {
        return '{"packages": [], "packages-dev": []}';
    }

    public function test_run_php_build_and_run_composer_write_the_identical_runtime_manifest(): void
    {
        $composerJson = $this->lockedPinnedComposerJson();
        $composerLock = $this->lockedPinnedComposerLock();
        $files = [
            '/home/acme/project/composer.json' => $composerJson,
            '/home/acme/project/composer.lock' => $composerLock,
        ];

        $decision = [
            'install_command' => 'composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader',
            'build_command' => '',
        ];

        $manifestPath = '/home/acme/project/' . PhpHostBuild::RUNTIME_MANIFEST_FILE;
        $lockPath = '/home/acme/project/' . PhpHostBuild::RUNTIME_LOCK_FILE;

        // Pass 1: the real, public runPhpBuild().
        $copiedFromPhpBuild = [];
        $system = $this->stubbedSystem($files, $copiedFromPhpBuild);
        (new HostCompile($this->stubbedProject($system)))->runPhpBuild(
            'panelalpha/php:8.4-apache-bookworm-pa20260910',
            $decision,
            '',
            true
        );

        $this->assertArrayHasKey($manifestPath, $copiedFromPhpBuild, 'runPhpBuild() never wrote a runtime manifest');
        $this->assertArrayHasKey($lockPath, $copiedFromPhpBuild, 'runPhpBuild() never copied the lock beside it');

        // Pass 2: the private runComposer(), against the same source files,
        // driven directly since its only caller guards it behind a
        // `vendor/autoload.php` check this fixture does not need to satisfy.
        $copiedFromRunComposer = [];
        $system2 = $this->stubbedSystem($files, $copiedFromRunComposer);
        $hostCompile2 = new HostCompile($this->stubbedProject($system2));
        $method = new ReflectionMethod(HostCompile::class, 'runComposer');
        $method->setAccessible(true);
        $method->invoke($hostCompile2);

        $this->assertArrayHasKey($manifestPath, $copiedFromRunComposer, 'runComposer() never wrote a runtime manifest');
        $this->assertArrayHasKey($lockPath, $copiedFromRunComposer, 'runComposer() never copied the lock beside it');

        // Both passes read the same composer.json/composer.lock through the
        // same private runtimeComposerManifest(), so the file they hand
        // Composer has to be the same name with the same bytes.
        $this->assertSame(
            $copiedFromPhpBuild[$manifestPath],
            $copiedFromRunComposer[$manifestPath],
            'runPhpBuild() and runComposer() disagreed on the runtime manifest content'
        );
        $this->assertSame(
            $copiedFromPhpBuild[$lockPath],
            $copiedFromRunComposer[$lockPath],
            'runPhpBuild() and runComposer() disagreed on the copied lock content'
        );
        $this->assertSame($composerJson, $copiedFromPhpBuild[$manifestPath], 'the manifest must carry composer.json byte for byte');
    }
}
