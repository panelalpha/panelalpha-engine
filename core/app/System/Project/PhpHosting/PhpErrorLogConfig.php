<?php

namespace App\System\Project\PhpHosting;

use App\Lib\Project\PhpErrorLog;
use App\System\Project\PhpHosting;

/**
 * Every installed PHP version of a project logs its errors into the project's
 * own `log/php`. New projects copy the templates with those lines already;
 * this is what writes them into an existing project and makes the directory
 * the account's worker can write to.
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
            $this->appendMissingLines("{$projectDir}/php/{$version}/" . self::INI_FILE, self::INI_LINES);
            $this->appendMissingLines("{$projectDir}/php/{$version}/" . self::POOL_FILE, self::POOL_LINES);
        }

        $this->ensureLogDir($project, "{$projectDir}/log/php");
        $this->ensureLogFile($project, "{$projectDir}/log/php");
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
     * run as the account, so the log directory is handed to the account.
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
     * append to and the API must refuse to read. Create it as the account, or
     * hand an existing regular file over; a link is unlinked, never followed,
     * and chown uses -h so the name is changed, not its target.
     */
    private function ensureLogFile(PhpHosting $project, string $dir): void
    {
        $system = $project->system();
        $owner = $project->userModel()->getChownString();
        if ($owner === null) {
            return;
        }

        $file = $dir . '/' . PhpErrorLog::FILE_NAME;

        if (is_link($file)) {
            $system->exec(['sudo', 'rm', '-f', '--', $file]);
        }
        if (file_exists($file) && !is_file($file)) {
            return;
        }
        if (!is_file($file)) {
            $system->exec(['sudo', 'touch', '--', $file]);
        }

        $system->exec(['sudo', 'chown', '-h', $owner, $file]);
        $system->exec(['sudo', 'chmod', '664', $file]);
    }
}
