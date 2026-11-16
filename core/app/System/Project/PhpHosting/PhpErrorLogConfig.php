<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Project\PhpErrorLog;
use App\System\Privileged;
use App\System\Project\PhpHosting;

/**
 * Makes every installed PHP version of an existing project log into its own `log/php`, as new projects' templates do,
 * in a directory the account's worker can write to, keeps a start-up warning out of it, and has PHP re-read a
 * document root's user INI within seconds.
 */
final class PhpErrorLogConfig
{
    private const INI_FILE = 'default.ini';

    private const POOL_FILE = 'fpm/pool.d/www.conf';

    /** Seconds PHP caches a document root's user INI; the project's ini carries it for lsphp. */
    public const USER_INI_CACHE_TTL = '3';

    /** The pool holds the same value for php-fpm, as an admin value that no project ini can raise. */
    public const POOL_USER_INI_CACHE_TTL_DIRECTIVE = 'php_admin_value[user_ini.cache_ttl]';

    public const POOL_USER_INI_CACHE_TTL_LINE = self::POOL_USER_INI_CACHE_TTL_DIRECTIVE.' = '.self::USER_INI_CACHE_TTL;

    /**
     * PHP keeps a document root's user INI for five minutes unless its ini says otherwise,
     * and a saved setting is meant to reach the site in seconds.
     *
     * @var array<string, string> directive => line appended when it is absent
     */
    private const INI_LINES = [
        'log_errors' => 'log_errors = On',
        'error_log' => 'error_log = /var/log/php/php_error.log',
        'user_ini.cache_ttl' => 'user_ini.cache_ttl = '.self::USER_INI_CACHE_TTL,
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

    /** @var list<string> directives the engine holds at its own value, whatever the file says */
    private const ENFORCED_LINES = ['user_ini.cache_ttl'];

    /** @var array<string, string> */
    private const POOL_LINES = [
        'catch_workers_output' => 'catch_workers_output = yes',
        'decorate_workers_output' => 'decorate_workers_output = no',
        self::POOL_USER_INI_CACHE_TTL_DIRECTIVE => self::POOL_USER_INI_CACHE_TTL_LINE,
    ];

    /** @var list<string> */
    private const ENFORCED_POOL_LINES = [self::POOL_USER_INI_CACHE_TTL_DIRECTIVE];

    public function apply(PhpHosting $project): void
    {
        $system = $project->system();
        $projectDir = $system->projectDirPath($project->username());

        foreach ($system->php()->listAvailablePhpVersions() as $version) {
            $this->appendMissingLines(
                "{$projectDir}/php/{$version}/".self::INI_FILE,
                $this->iniLines($version),
                self::ENFORCED_LINES
            );
            $this->appendMissingLines(
                "{$projectDir}/php/{$version}/".self::POOL_FILE,
                self::POOL_LINES,
                self::ENFORCED_POOL_LINES
            );
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
     * A directive already in the file is left alone, so a project that carries its own value keeps it,
     * except the ones the engine holds: those are rewritten, never duplicated.
     *
     * @param  array<string, string>  $lines
     * @param  list<string>  $enforced
     */
    private function appendMissingLines(string $path, array $lines, array $enforced = []): void
    {
        if (! is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);
        $changed = false;
        foreach ($lines as $directive => $line) {
            $pattern = '/^\s*'.preg_quote($directive, '/').'\s*=(.*)$/mi';
            if (preg_match($pattern, $contents, $found) === 1) {
                if (! in_array($directive, $enforced, true) || trim($found[1]) === self::lineValue($line)) {
                    continue;
                }
                $contents = (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
                $changed = true;

                continue;
            }
            $contents = rtrim($contents, "\n")."\n{$line}\n";
            $changed = true;
        }

        if ($changed) {
            file_put_contents($path, $contents);
        }
    }

    private static function lineValue(string $line): string
    {
        return trim(explode('=', $line, 2)[1]);
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
