<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * Named core operations for a PHP hosting WordPress install.
 *
 * Status reports version, whether core is installed, and a state that says
 * why it is not: no core files, no wp-config.php, no database connection, or
 * a reachable database without WordPress tables. On an is-installed exit 1,
 * missing core and wp-config.php are read from WP-CLI's own messages, which
 * are never translated; the database is then probed with `wp db query`,
 * since WordPress's own connection error is in the site's language. Anything
 * the engine cannot tell is "unknown", never a guess. Install and update are
 * thin wrappers around WP-CLI. HTTP, artisan, and MCP tools are adapters.
 * Path resolution and the runner come from WpCli; a non-zero exit on install
 * or update becomes Exception. Any is-installed exit other than 0 or 1 (a
 * fatal error loading the site) is a failure, never "not installed", so a
 * caller does not install over a broken but existing site. Download is for
 * PHP hosting only and never writes over an existing core: there is no --force.
 */
final class Core
{
    public const VERSION_FILE = 'wp-includes/version.php';

    private const DEFAULT_LOCALE = 'en_US';

    public const STATE_INSTALLED = 'installed';

    public const STATE_NOT_INSTALLED = 'not_installed';

    public const STATE_NO_CONFIG = 'no_config';

    public const STATE_DB_UNREACHABLE = 'db_unreachable';

    public const STATE_NO_CORE = 'no_core';

    public const STATE_UNKNOWN = 'unknown';

    /** WP-CLI's error when the path holds no wp-includes/version.php. */
    public const NO_CORE_MESSAGE = 'This does not seem to be a WordPress installation.';

    /** WP-CLI's error when core is there but no wp-config.php is found for it. */
    public const NO_CONFIG_MESSAGE = "'wp-config.php' not found.";

    /** Checked in this order; the first message found in the output decides the state. */
    private const NOT_INSTALLED_REASONS = [
        self::NO_CORE_MESSAGE => self::STATE_NO_CORE,
        self::NO_CONFIG_MESSAGE => self::STATE_NO_CONFIG,
    ];

    /** Loads wp-config.php only and runs the MySQL client against the database it names. */
    public const DB_PROBE = ['db', 'query', 'SELECT 1'];

    /** A MySQL or MariaDB client error code, from `mysql` (ERROR 2005) or `mysqlcheck` (Got error: 2005). */
    public const MYSQL_CLIENT_ERROR_PATTERN = '/\b(?:ERROR|Got error:) \d{4}\b/';

    /** PHP diagnostics WordPress may print under CLI without anything being wrong. */
    public const PHP_DIAGNOSTIC_PATTERN = '/^(?:PHP )?(?:Warning|Notice|Deprecated|Strict Standards):/';

    public function __construct(
        private WpCli $cli,
    ) {}

    /**
     * @return array{installed: bool, version: string|null, state: string}
     */
    public function status(): array
    {
        $state = $this->installState();
        $installed = $state === self::STATE_INSTALLED;

        return [
            'installed' => $installed,
            'version' => $state === self::STATE_NO_CORE ? null : $this->version($installed),
            'state' => $state,
        ];
    }

    private function installState(): string
    {
        try {
            $result = $this->cli->run(['core', 'is-installed']);
        } catch (WordPressNotFound) {
            return self::STATE_NO_CORE;
        }

        return match ($result['exit_code'] ?? 1) {
            0 => self::STATE_INSTALLED,
            1 => $this->notInstalledState($result),
            default => throw new Exception($this->cli->failureMessage($result)),
        };
    }

    /**
     * @param  array{exit_code?: int, stdout?: string, stderr?: string}  $result
     */
    private function notInstalledState(array $result): string
    {
        $output = $this->output($result);
        foreach (self::NOT_INSTALLED_REASONS as $message => $state) {
            if (str_contains($output, $message)) {
                return $state;
            }
        }

        return $this->databaseState($output);
    }

    private function databaseState(string $isInstalledOutput): string
    {
        $probe = $this->cli->run(self::DB_PROBE);
        if (($probe['exit_code'] ?? 1) === 0) {
            return $this->withoutPhpDiagnostics($isInstalledOutput) === ''
                ? self::STATE_NOT_INSTALLED
                : self::STATE_UNKNOWN;
        }

        return preg_match(self::MYSQL_CLIENT_ERROR_PATTERN, $this->output($probe)) === 1
            ? self::STATE_DB_UNREACHABLE
            : self::STATE_UNKNOWN;
    }

    /**
     * @param  array{exit_code?: int, stdout?: string, stderr?: string}  $result
     */
    private function output(array $result): string
    {
        return trim(($result['stderr'] ?? '')."\n".($result['stdout'] ?? ''));
    }

    private function withoutPhpDiagnostics(string $output): string
    {
        $lines = array_filter(
            preg_split('/\R/', $output) ?: [],
            fn (string $line): bool => trim($line) !== '' && preg_match(self::PHP_DIAGNOSTIC_PATTERN, ltrim($line)) !== 1,
        );

        return implode("\n", $lines);
    }

    /** Version from wp-includes/version.php; null when it cannot be read and core is not installed. */
    private function version(bool $installed): ?string
    {
        $result = $this->cli->run(['core', 'version']);
        if (($result['exit_code'] ?? 1) !== 0) {
            if (! $installed) {
                return null;
            }

            throw new Exception($this->cli->failureMessage($result));
        }
        $version = trim($result['stdout']);

        return $version !== '' ? $version : null;
    }

    /**
     * Fetch WordPress core into the install directory with `wp core download`;
     * a directory that already holds core is a 409, a container project a 422.
     *
     * @return array{version: string|null, locale: string, path: string}
     */
    public function download(?string $locale = null, ?string $version = null): array
    {
        $this->refuseContainerProject();
        $path = $this->cli->resolvePath();
        $this->refuseExistingCore($path);
        $result = $this->cli->runOrFail(self::downloadArgs($locale, $version));
        $reported = self::reportedDownload($result['stdout']);

        return [
            'version' => $reported['version'] ?? $version,
            'locale' => $reported['locale'] ?? $locale ?? self::DEFAULT_LOCALE,
            'path' => $path,
        ];
    }

    /**
     * @param array{
     *     url: string,
     *     title: string,
     *     admin_user: string,
     *     admin_email: string,
     *     admin_password: string,
     *     skip_email?: bool,
     * } $fields
     * @return array{message: string}
     */
    public function install(array $fields): array
    {
        $args = [
            'core',
            'install',
            '--url='.$fields['url'],
            '--title='.$fields['title'],
            '--admin_user='.$fields['admin_user'],
            '--admin_email='.$fields['admin_email'],
            '--admin_password='.$fields['admin_password'],
        ];
        if (! empty($fields['skip_email'])) {
            $args[] = '--skip-email';
        }
        $result = $this->cli->runOrFail($args);

        return [
            'message' => trim($result['stdout']),
        ];
    }

    /**
     * Core versions newer than the installed one, from WP-CLI
     * `core check-update`; empty when WordPress is up to date.
     *
     * @return list<array{version: string, update_type: string, package_url: string}>
     */
    public function checkUpdate(): array
    {
        $result = $this->cli->runOrFail(['core', 'check-update', '--format=json']);
        if (trim($result['stdout']) === '') {
            return [];
        }

        /** @var list<array{version: string, update_type: string, package_url: string}> */
        return JsonOutput::decodeList($result['stdout']);
    }

    /**
     * @return array{version: string, message: string}
     */
    public function update(string $version): array
    {
        $result = $this->cli->runOrFail(
            ['core', 'update', '--version='.$version],
        );

        return [
            'version' => $version,
            'message' => trim($result['stdout']),
        ];
    }

    private function refuseContainerProject(): void
    {
        if ($this->cli->runsInContainer()) {
            throw new Exception(
                'Downloading WordPress core applies only to PHP hosting projects. '
                    .'A project running in its own containers gets WordPress from its image.',
                422,
            );
        }
    }

    private function refuseExistingCore(string $path): void
    {
        if ($this->cli->installHasFile(self::VERSION_FILE)) {
            throw new Exception("WordPress core is already present in {$path}; it is not downloaded over it.", 409);
        }
    }

    /** @return list<string> */
    private static function downloadArgs(?string $locale, ?string $version): array
    {
        $args = ['core', 'download'];
        if ($locale !== null) {
            $args[] = '--locale='.$locale;
        }
        if ($version !== null) {
            $args[] = '--version='.$version;
        }

        return $args;
    }

    /**
     * Version and locale from WP-CLI's "Downloading WordPress 6.7.1 (en_US)..." line.
     *
     * @return array{version?: string, locale?: string}
     */
    private static function reportedDownload(string $stdout): array
    {
        if (preg_match('/^Downloading WordPress (\S+) \(([^)\s]+)\)/m', $stdout, $match) !== 1) {
            return [];
        }

        return ['version' => $match[1], 'locale' => $match[2]];
    }
}
