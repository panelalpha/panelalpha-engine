<?php

namespace Tests\Support;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;

trait HostCompilePhpPlatformPinFixtures
{
    /** @var array<string, string> absolute account path => contents */
    private array $files = [];

    /** @var array<string, string> target path => contents, from every `files.copy` the build issued */
    private array $copiedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = [];
        $this->copiedTo = [];
    }

    /**
     * Run the real host PHP build against a project whose files are the map
     * given, and answer the container request it made.
     *
     * @param  array<string, string>  $files  path under the project => contents
     * @param  array<string, mixed>  $decision  the matched manifest's, as
     *                                          DetectProjectStrategy would hand it over
     */
    private function build(array $files, array $decision, string $appRoot = '', bool $hasComposer = true): ?array
    {
        foreach ($files as $path => $contents) {
            $this->files['/home/acme/project/'.ltrim($path, '/')] = $contents;
        }

        $filesystem = $this->files;
        $copiedTo = &$this->copiedTo;

        $system = new class($filesystem, $copiedTo) extends System
        {
            /** @var array<string, string> */
            private array $files;

            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files
             *  @param array<string, string> $copiedTo */
            public function __construct(array $files, private array &$copiedTo)
            {
                $this->files = $files;
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class($engine, $files) extends SystemFilesystem
                {
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

            /**
             * Real writes go through `Filesystem::writeFileReplacingPath()`,
             * which stages the content in a real temp file, copies it out with
             * `files.copy <tmp> <staged>` and renames that onto the target
             * ({@see StagedRename}). Reading the temp file here, before it is
             * unlinked, is how the test observes what was actually written
             * without a filesystem the account owns.
             */
            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                [$source, $target] = LocalRoot::action($argv) === 'files.copy' ? LocalRoot::values($argv) : [null, null];
                if ($source !== null && is_file($source)) {
                    $this->copiedTo[$target] = (string) file_get_contents($source);
                }
                $this->renameStaged($argv);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? $cmd : [$cmd];

                $this->renameStaged((array) $cmd);

                return $this->answer((array) $cmd);
            }

            /**
             * One shared device per path, so a home write finds its stage on the same filesystem.
             *
             * @param  list<string>  $argv
             */
            private function answer(array $argv): Process
            {
                $output = LocalRoot::action($argv) === 'files.deviceNumbers' ? str_repeat("1\n", count(LocalRoot::values($argv))) : '';
                $process = new Process(['php', '-r', 'echo $argv[1];', $output]);
                $process->run();

                return $process;
            }

            /** @param  list<string>  $argv */
            private function renameStaged(array $argv): void
            {
                $values = LocalRoot::values($argv);
                [$staged, $target] = match (LocalRoot::action($argv)) {
                    'files.rename' => $values,
                    'account.renameInHome' => array_slice($values, 1),
                    default => [null, null],
                };
                if ($staged !== null && isset($this->copiedTo[$staged])) {
                    $this->copiedTo[$target] = $this->copiedTo[$staged];
                    unset($this->copiedTo[$staged]);
                }
            }
        };

        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder);

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        // The real reader, over the stubbed System's file map, so the minor is
        // resolved from the same composer.json the deploy would read.
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $dind->method('innerDocker')->willReturn($this->hostWithTheBase());

        (new HostCompile($dind))->runPhpBuild(
            'panelalpha/php:8.4-apache-bookworm-pa20260910',
            $decision,
            $appRoot,
            $hasComposer
        );

        // The first command is the cache preparation; the build container is
        // the last thing the pass runs.
        if ($system->commands === []) {
            return null;
        }

        $build = end($system->commands);

        return $build === false ? null : $build;
    }

    private function hostWithTheBase(): InnerDocker
    {
        $bases = $this->createStub(SharedBaseImages::class);
        $bases->method('ensureOnHost')->willReturn(true);
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('bases')->willReturn($bases);

        return $inner;
    }
}
