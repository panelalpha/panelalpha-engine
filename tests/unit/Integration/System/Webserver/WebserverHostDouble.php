<?php

namespace Tests\Integration\System\Webserver;

use App\System;
use App\System\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

/**
 * A host for the webserver drivers: files live in memory, every command and write is journaled in order, and
 * `test -e`, the broker's one-file listing and `mv` work on those files.
 */
final class WebserverHostDouble extends System
{
    private const ROOTS = 'PA_ROOT_LOCAL_ROOTS';

    /** @var array<string, string> */
    public array $files = [];

    /** @var list<string> */
    public array $journal = [];

    public function __construct(private string $templatesDir) {}

    public function engineDirPath(): string
    {
        return '/opt/engine';
    }

    public function templatesDirPath(): string
    {
        return $this->templatesDir;
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->journal[] = 'exec '.self::line($cmd);

        return '';
    }

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        $this->journal[] = 'run '.self::line($cmd);
        $argv = is_array($cmd) ? $this->plain($cmd) : [];
        if (array_slice($argv, 0, 2) === ['test', '-e']) {
            return isset($this->files[$argv[2]]) ? FakeProcess::ok() : FakeProcess::failed();
        }
        if (array_slice($argv, 0, 3) === ['ls', '-1', '--']) {
            return isset($this->files[$argv[3]]) ? FakeProcess::ok() : FakeProcess::failed();
        }
        if (array_slice($argv, 0, 2) === ['mv', '--']) {
            $this->move($argv[2], $argv[3]);
        }

        return FakeProcess::ok();
    }

    private function move(string $from, string $to): void
    {
        if (array_key_exists($from, $this->files)) {
            $this->files[$to] = $this->files[$from];
            unset($this->files[$from]);
        }
    }

    public function filesystem(): Filesystem
    {
        return new class ($this) extends Filesystem {
            public function __construct(private WebserverHostDouble $host)
            {
                parent::__construct($host);
            }

            public function fileGetContents(string $path): string
            {
                if (array_key_exists($path, $this->host->files)) {
                    return $this->host->files[$path];
                }
                if (is_file($path)) {
                    return (string) file_get_contents($path);
                }

                throw new \RuntimeException("no such file: {$path}");
            }

            public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
            {
                $this->host->journal[] = "put {$path} ".($chown ?? '-')."\n{$contents}\n<<< end of {$path}";
                $this->host->files[$path] = $contents;
            }

            public function fileExists(string $path): bool
            {
                return array_key_exists($path, $this->host->files);
            }

            public function directoryExists(string $path): bool
            {
                return false;
            }

            public function ls(string $path): array
            {
                $this->host->journal[] = "ls {$path}";
                $names = [];
                foreach (array_keys($this->host->files) as $file) {
                    if (dirname($file) === $path) {
                        $names[] = basename($file);
                    }
                }
                sort($names);

                return $names;
            }
        };
    }

    public function snapshot(): string
    {
        return implode("\n", $this->journal)."\n";
    }

    /** @param string|list<string> $cmd */
    private static function line(string|array $cmd): string
    {
        return is_array($cmd) ? (string) json_encode($cmd, JSON_UNESCAPED_SLASHES) : $cmd;
    }

    /**
     * The broker's plan with its engine root moved to this host's.
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private function plain(array $cmd): array
    {
        $before = getenv(self::ROOTS);
        putenv(self::ROOTS.'='.json_encode(['engine' => $this->engineDirPath()]));
        try {
            return LocalRoot::plain($cmd);
        } finally {
            putenv($before === false ? self::ROOTS : self::ROOTS.'='.$before);
        }
    }
}
