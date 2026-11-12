<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Project\PhpErrorLog;
use App\System\Privileged;
use App\System\Project\PhpHosting;

/**
 * Makes every installed PHP version of an existing project log into its own `log/php`, as new projects' templates do,
 * in a directory the account's worker can write to, and keeps a start-up warning out of it.
 */
final class PhpErrorLogConfig
{
    private const INI_FILE = 'default.ini';

    private const POOL_FILE = 'fpm/pool.d/www.conf';

    /** @var array<string, string> directive => line appended when it is absent */
    private const INI_LINES = [
        'log_errors' => 'log_errors = On',
        'error_log' => 'error_log = /var/log/php/php_error.log',
    ];

    /**
     * From 8.4 PHP reserves a JIT buffer by default, and with the ionCube loader
     * installed it then warns at every start that the JIT is disabled.
     *
     * @var array<string, string>
     */
    private const JIT_LINES = [
        'opcache.jit_buffer_size' => 'opcache.jit_buffer_size = 0',
    ];

    private const JIT_BUFFER_DEFAULT_SINCE = '8.4';

    /** @var array<string, string> */
    private const POOL_LINES = [
        'catch_workers_output' => 'catch_workers_output = yes',
        'decorate_workers_output' => 'decorate_workers_output = no',
    ];

    public function apply(PhpHosting $project): void
    {
        $system = $project->system();
        $projectDir = $system->projectDirPath($project->username());

        foreach ($system->php()->listAvailablePhpVersions() as $version) {
            $this->appendMissingLines("{$projectDir}/php/{$version}/".self::INI_FILE, $this->iniLines($version));
            $this->appendMissingLines("{$projectDir}/php/{$version}/".self::POOL_FILE, self::POOL_LINES);
        }

        $this->ensureLogDir($project, "{$projectDir}/log/php");
        $this->ensureLogFile($project, "{$projectDir}/log/php");
    }

    /**
     * @return array<string, string>
     */
    private function iniLines(string $version): array
    {
        if (version_compare($version, self::JIT_BUFFER_DEFAULT_SINCE, '<')) {
            return self::INI_LINES;
        }

        return self::INI_LINES + self::JIT_LINES;
    }

    /**
     * A directive already in the file is left alone, so a project that carries
     * its own value keeps it.
     *
     * @param  array<string, string>  $lines
     */
    private function appendMissingLines(string $path, array $lines): void
    {
        if (! is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);
        $changed = false;
        foreach ($lines as $directive => $line) {
            if (preg_match('/^\s*'.preg_quote($directive, '/').'\s*=/mi', $contents) === 1) {
                continue;
            }
            $contents = rtrim($contents, "\n")."\n{$line}\n";
            $changed = true;
        }

        if ($changed) {
            file_put_contents($path, $contents);
        }
    }

    /**
     * The project directory is www-data's but FPM workers run as the account, so the log directory is handed to it.
     * Root names only `log/php`, an engine-owned `log` entry the account cannot rename or replace, never what it holds.
     */
    private function ensureLogDir(PhpHosting $project, string $dir): void
    {
        $system = $project->system();
        $system->exec(Privileged::files()->makeDirectories($dir));
        $owner = $project->userModel()->getChownString();
        if ($owner !== null) {
            $system->exec(Privileged::files()->changeOwner($owner, $dir));
        }
        $system->exec(Privileged::files()->changeMode('755', $dir));
    }

    /**
     * FPM's master creates it as root, which the worker cannot append to and the API must refuse to read. It all runs
     * as the account in a directory it can rewrite at any time, so a link it swaps in reaches only what it could itself.
     */
    private function ensureLogFile(PhpHosting $project, string $dir): void
    {
        $owner = $project->userModel()->getChownString();
        if ($owner === null) {
            return;
        }

        $file = $dir.'/'.PhpErrorLog::FILE_NAME;
        [$uid, $gid] = $project->project()->fileManager()->accountIds();
        $project->system()->exec(Privileged::account()->ensureLogFile($uid, $gid, $file, $owner));
    }
}
