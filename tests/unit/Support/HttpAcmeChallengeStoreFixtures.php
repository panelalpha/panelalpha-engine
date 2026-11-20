<?php

namespace Tests\Support;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;

trait HttpAcmeChallengeStoreFixtures
{
    private const DIR = '/engine/data/webserver/acme-challenges/site.test';

    /** @param  array<string, string>  $files */
    private static function host(array $files = [], int $listExit = 0): System
    {
        $system = new class extends System
        {
            /** @var array<string, string> */
            public array $files = [];

            /** @var list<string> */
            public array $dirs = [];

            public int $listExit = 0;

            /** @var list<array<string>|string> */
            public array $commands = [];

            public function engineDirPath(): string
            {
                return '/engine';
            }

            public function directoryExists(string $path): bool
            {
                if (in_array($path, $this->dirs, true)) {
                    return true;
                }
                foreach (array_keys($this->files) as $file) {
                    if (str_starts_with($file, $path.'/')) {
                        return true;
                    }
                }

                return false;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.listAllNames') {
                    if ($this->listExit !== 0) {
                        return FakeProcess::failed('ls failed', $this->listExit);
                    }
                    $dir = LocalRoot::values($cmd)[0];
                    $names = ['.', '..'];
                    foreach (array_keys($this->files) as $file) {
                        if (dirname($file) === $dir) {
                            $names[] = basename($file);
                        }
                    }

                    return FakeProcess::ok(implode("\n", $names)."\n");
                }

                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;
                $action = is_array($cmd) ? LocalRoot::action($cmd) : null;
                foreach ($action === null ? [] : LocalRoot::values($cmd) as $path) {
                    foreach (array_keys($this->files) as $file) {
                        if ($file === $path || ($action === 'files.removeTree' && str_starts_with($file, $path.'/'))) {
                            unset($this->files[$file]);
                        }
                    }
                    $this->dirs = array_values(array_diff($this->dirs, [$path]));
                }

                return '';
            }

            public function filesystem(): Filesystem
            {
                $host = $this;

                return new class($host) extends Filesystem
                {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->host->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path];
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        $this->host->dirs[] = $target;
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                        $this->host->commands[] = "put {$path} {$chmod}";
                    }
                };
            }
        };
        $system->files = $files;
        $system->listExit = $listExit;

        return $system;
    }
}
