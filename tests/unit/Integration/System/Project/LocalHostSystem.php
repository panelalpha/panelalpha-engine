<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * Host System that applies the file commands of root broker requests to the local tree (no Docker).
 */
final class LocalHostSystem extends System
{
    private const RUN_BY_THE_BROKER_ITSELF = ['account.renameInHome', 'account.readInHome', 'files.existingRealPaths'];

    private const ROOTS = 'PA_ROOT_LOCAL_ROOTS';

    public function __construct(
        private string $engineRoot,
        private string $homesRoot,
    ) {
    }

    public function engineDirPath(): string
    {
        return $this->engineRoot;
    }

    public function homesDirPath(): string
    {
        return $this->homesRoot;
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->apply($this->requested($cmd));

        return '';
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

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $command = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
        if (in_array(LocalRoot::action($command), self::RUN_BY_THE_BROKER_ITSELF, true)) {
            return $this->runAsCaller($command);
        }
        $argv = $this->requested($command);
        $exit = 0;
        $out = '';
        if (($argv[0] ?? '') === 'stat' && ($argv[2] ?? '') === '%a') {
            clearstatcache();
            $perms = @fileperms($argv[3] ?? '');
            [$exit, $out] = $perms === false ? [1, ''] : [0, sprintf('%o', $perms & 07777) . "\n"];
        } elseif (($argv[0] ?? '') === 'stat' && ($argv[2] ?? '') === '%d') {
            [$exit, $out] = $this->devices(array_slice($argv, 3));
        } elseif (($argv[0] ?? '') === 'test') {
            $flag = $argv[1] ?? '';
            $path = $argv[2] ?? '';
            $ok = match ($flag) {
                '-f' => is_file($path),
                '-d' => is_dir($path),
                '-L' => is_link($path),
                default => false,
            };
            $exit = $ok ? 0 : 1;
        } else {
            $this->apply($argv);
        }

        return new class ($exit, $out) extends Process {
            public function __construct(private int $code, private string $out)
            {
                parent::__construct(['true']);
            }

            public function isSuccessful(): bool
            {
                return $this->code === 0;
            }

            public function getExitCode(): ?int
            {
                return $this->code;
            }

            public function getOutput(): string
            {
                return $this->out;
            }

            public function getErrorOutput(): string
            {
                return '';
            }
        };
    }

    /**
     * The broker's command for a request without its `--`, [] for any other command.
     *
     * @param string|list<string> $cmd
     * @return list<string>
     */
    private function requested(string|array $cmd): array
    {
        if (!is_array($cmd) || !LocalRoot::isRequest($cmd)) {
            return [];
        }

        return array_values(array_filter($this->planned($cmd), static fn (string $word): bool => $word !== '--'));
    }

    /**
     * The broker's plan with its engine and homes roots moved to this tree.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private function planned(array $cmd): array
    {
        $before = getenv(self::ROOTS);
        putenv(self::ROOTS . '=' . $this->roots());
        try {
            return LocalRoot::plain($cmd);
        } finally {
            putenv($before === false ? self::ROOTS : self::ROOTS . '=' . $before);
        }
    }

    private function roots(): string
    {
        return json_encode([
            'engine' => rtrim($this->engineRoot, '/'),
            'homes' => rtrim($this->homesRoot, '/'),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $argv
     */
    private function apply(array $argv): void
    {
        $op = $argv[0] ?? '';
        if ($op === 'cp') {
            $source = $argv[1] ?? '';
            $target = $argv[2] ?? '';
            if ($source !== '' && $target !== '' && is_file($source)) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0777, true);
                }
                copy($source, $target);
            }

            return;
        }
        if ($op === 'mkdir') {
            $path = (string) end($argv);
            $mode = array_search('-m', $argv, true);
            if ($path !== '' && !is_dir($path)) {
                mkdir($path, 0777, true);
                if ($mode !== false) {
                    chmod($path, (int) octdec((string) $argv[$mode + 1]));
                }
            }

            return;
        }
        if ($op === 'rm') {
            $recursive = in_array('-rf', $argv, true) || in_array('-r', $argv, true);
            $path = (string) end($argv);
            if ($path === '' || $path === '-rf' || $path === '-f' || $path === '-r') {
                return;
            }
            if ($recursive && is_dir($path)) {
                $this->removeTree($path);

                return;
            }
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }

            return;
        }
        if ($op === 'chmod') {
            $path = $argv[2] ?? '';
            if ($path !== '' && file_exists($path)) {
                chmod($path, (int) octdec((string) ($argv[1] ?? '')));
            }

            return;
        }
        if ($op === 'mv') {
            $from = $argv[count($argv) - 2] ?? '';
            $to = $argv[count($argv) - 1] ?? '';
            if ($from !== '' && $to !== '' && file_exists($from)) {
                rename($from, $to);
            }
        }
    }

    /**
     * `stat -c %d` over each path: its device, one per line, without following a final symlink.
     *
     * @param list<string> $paths
     * @return array{0: int, 1: string}
     */
    private function devices(array $paths): array
    {
        $out = '';
        foreach ($paths as $path) {
            $stat = @lstat($path);
            if ($stat === false) {
                return [1, $out];
            }
            $out .= $stat['dev'] . "\n";
        }

        return [0, $out];
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

    /**
     * A native action, run for real by pa-root-local: it walks the local tree
     * the same way it walks /home, and needs no root to do it.
     *
     * @param list<string> $argv
     */
    private function runAsCaller(array $argv): Process
    {
        $process = new Process(LocalRoot::argv($argv), null, [self::ROOTS => $this->roots()]);
        $process->run();

        return $process;
    }
}
