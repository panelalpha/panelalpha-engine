<?php

namespace Tests\Unit\System\Project;

use App\System;
use Symfony\Component\Process\Process;

/**
 * Host System that applies sudo file commands to the local tree (no Docker).
 */
final class LocalHostSystem extends System
{
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
        $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
        $this->apply($argv);

        return '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $argv = is_array($cmd) ? array_values($cmd) : (preg_split('/\s+/', trim($cmd)) ?: []);
        $exit = 0;
        $out = '';
        if (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'stat' && ($argv[3] ?? '') === '%a') {
            clearstatcache();
            $perms = @fileperms($argv[4] ?? '');
            [$exit, $out] = $perms === false ? [1, ''] : [0, sprintf('%o', $perms & 07777) . "\n"];
        } elseif (($argv[0] ?? '') === 'sudo' && ($argv[1] ?? '') === 'test') {
            $flag = $argv[2] ?? '';
            $path = $argv[3] ?? '';
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
     * @param list<string> $argv
     */
    private function apply(array $argv): void
    {
        if (($argv[0] ?? '') !== 'sudo') {
            return;
        }
        $op = $argv[1] ?? '';
        if ($op === 'cp') {
            $source = $argv[2] ?? '';
            $target = $argv[3] ?? '';
            if ($source !== '' && $target !== '' && is_file($source)) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0777, true);
                }
                copy($source, $target);
            }

            return;
        }
        if ($op === 'mkdir') {
            $path = ($argv[2] ?? '') === '-p' ? ($argv[3] ?? '') : ($argv[2] ?? '');
            if ($path !== '' && !is_dir($path)) {
                mkdir($path, 0777, true);
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
            $path = $argv[3] ?? '';
            if ($path !== '' && file_exists($path)) {
                chmod($path, (int) octdec((string) ($argv[2] ?? '')));
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
