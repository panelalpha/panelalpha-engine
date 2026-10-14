<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Project\PhpErrorLog;
use App\System\Project\PhpHosting;

/**
 * Every installed PHP version of a project logs its errors into the project's
 * own `log/php`. New projects copy the templates with those lines already;
 * this is what writes them into an existing project and makes the directory
 * the account's worker can write to, and keeps a start-up warning out of it.
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

    /**
     * Run as the account: `$1` the file, `$2` its uid:gid. A link is unlinked,
     * anything but a regular file is left alone, and a file someone else owns
     * is replaced by an account-owned copy, since the account cannot chown it.
     */
    private const ENSURE_FILE_SCRIPT = 'f=$1; if [ -L "$f" ]; then rm -f -- "$f" || exit 1; fi; '
        . 'if [ -e "$f" ] && [ ! -f "$f" ]; then exit 0; fi; '
        . 'if [ -f "$f" ] && [ "$(stat -c %u:%g -- "$f")" != "$2" ]; then '
        . 't=$(mktemp -- "$f.XXXXXX") || exit 1; cat -- "$f" > "$t" 2>/dev/null; mv -f -- "$t" "$f" || exit 1; fi; '
        . 'touch -- "$f" && chmod 664 -- "$f"';

    public function apply(PhpHosting $project): void
    {
        $system = $project->system();
        $projectDir = $system->projectDirPath($project->username());

        foreach ($system->php()->listAvailablePhpVersions() as $version) {
            $this->appendMissingLines("{$projectDir}/php/{$version}/" . self::INI_FILE, $this->iniLines($version));
            $this->appendMissingLines("{$projectDir}/php/{$version}/" . self::POOL_FILE, self::POOL_LINES);
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
     * @param array<string, string> $lines
     */
    private function appendMissingLines(string $path, array $lines): void
    {
        if (!is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);
        $changed = false;
        foreach ($lines as $directive => $line) {
            if (preg_match('/^\s*' . preg_quote($directive, '/') . '\s*=/mi', $contents) === 1) {
                continue;
            }
            $contents = rtrim($contents, "\n") . "\n{$line}\n";
            $changed = true;
        }

        if ($changed) {
            file_put_contents($path, $contents);
        }
    }

    /**
     * The whole project directory is owned by www-data, and the FPM workers
     * run as the account, so the log directory is handed to the account. Root
     * only names `log/php` itself, an entry of the engine-owned `log` that the
     * account cannot rename or replace, never anything inside it.
     */
    private function ensureLogDir(PhpHosting $project, string $dir): void
    {
        $system = $project->system();
        $system->exec(['sudo', 'mkdir', '-p', $dir]);
        $owner = $project->userModel()->getChownString();
        if ($owner !== null) {
            $system->exec(['sudo', 'chown', $owner, $dir]);
        }
        $system->exec(['sudo', 'chmod', '755', $dir]);
    }

    /**
     * FPM's master creates the file as root, which the account's worker cannot
     * append to and the API must refuse to read. Everything happens as the
     * account, inside a directory it can rewrite at any moment, so a link it
     * swaps in only ever reaches what it could already touch itself.
     */
    private function ensureLogFile(PhpHosting $project, string $dir): void
    {
        $owner = $project->userModel()->getChownString();
        if ($owner === null) {
            return;
        }

        $file = $dir . '/' . PhpErrorLog::FILE_NAME;
        $account = $project->project()->fileManager();
        $project->system()->exec($account->asAccount(['sh', '-c', self::ENSURE_FILE_SCRIPT, 'sh', $file, $owner]));
    }
}
