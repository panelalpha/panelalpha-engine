<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Projects;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Raw WP-CLI invocation for a project's WordPress install.
 *
 * Optional path is bound on construction (null = main domain document root on
 * PHP hosting; on a container project, the install the runner finds, and a
 * path is as the container sees it, absolute or relative to the container's
 * working directory, and an optional compose service picks the container).
 * An args --path= still wins over the bound path, matching PhpRuntime::runWpCli.
 *
 * Cache flush (named or raw) shares scheduleReloadAfterCacheFlush: after a
 * zero exit, when the host webserver is OpenLiteSpeed, a webserver reload is
 * scheduled in the background. Schedule failures are logged and do not fail
 * the call. An optional System collaborator is for tests that fake the
 * webserver; production leaves it null and constructs System on demand.
 *
 * With skipPlugins every run adds --skip-plugins --skip-themes, so a fatal
 * error in one plugin or theme does not block operations that do not need
 * them loaded (lists, wp-config, maintenance mode, database).
 */
final class WpCli
{
    /** Where the engine's WordPress mounts the project's checkout in its container. */
    private const CONTAINER_PROJECT_DIR = '/var/www/html';

    /** On PHP hosting, WP-CLI's working directory: the home, mounted a second time. */
    private const PHP_HOSTING_WORKING_DIR = '/var/www';

    public function __construct(
        private User $user,
        private ?string $path = null,
        private ?System $system = null,
        private ?string $service = null,
        private bool $skipPlugins = false,
    ) {}

    public static function for(
        User $user,
        ?string $path = null,
        ?System $system = null,
        ?string $service = null,
        bool $skipPlugins = false,
    ): self {
        return new self($user, $path, $system, $service, $skipPlugins);
    }

    /**
     * @param  list<string>  $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $args): array
    {
        if ($this->service !== null && ! $this->runsInContainer()) {
            throw new Exception('service applies only to WordPress in the project\'s own containers.');
        }
        $args = $this->mergePath($this->withSkips($args));
        $result = $this->user->project()->runWpCli($args, $this->service);
        $this->scheduleReloadAfterCacheFlush($args, $result);

        return $result;
    }

    /**
     * Run WP-CLI and throw when the exit code is non-zero.
     *
     * Named operations use this so a failure is an exception (HTTP error),
     * never a 200 body with exit_code. The raw command keeps run().
     *
     * @param  list<string>  $args
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function runOrFail(array $args): array
    {
        $result = $this->run($args);
        if (($result['exit_code'] ?? 1) !== 0) {
            throw new Exception($this->failureMessage($result));
        }

        return $result;
    }

    /**
     * A caller's value for a positional argument. WP-CLI reads any argument
     * starting with '-' as an option wherever it stands, and 2.8.1 ignores '--'.
     */
    public static function positional(string $value): string
    {
        if (str_starts_with($value, '-')) {
            throw new Exception("'{$value}' starts with '-', which WP-CLI would read as an option.");
        }

        return $value;
    }

    /**
     * A file WP-CLI reads or writes for the caller, as WP-CLI is to be given
     * it: on PHP hosting an absolute path inside the project home; in the
     * project's own containers a path below the checkout's mount, or one
     * relative to the install directory, WP-CLI's working directory there.
     * `..` is resolved first, so it cannot lead out.
     *
     * @throws ProblemException 422 on $field for any other path
     */
    public function projectFile(string $field, string $file): string
    {
        return $this->runsInContainer() ? $this->containerFile($field, $file) : $this->homeFile($field, $file);
    }

    /**
     * @param  array{exit_code?: int, stdout?: string, stderr?: string}  $result
     */
    public function failureMessage(array $result): string
    {
        $parts = [];
        foreach (['stderr', 'stdout'] as $key) {
            $chunk = trim((string) ($result[$key] ?? ''));
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return $parts !== [] ? implode("\n", $parts) : 'WP-CLI command failed.';
    }

    /**
     * Absolute WP-CLI --path inside the project home: the bound path read the
     * way the file manager reads one (absolute, or relative to the home), so a
     * directory outside the home is never the WP-CLI container's own;
     * otherwise the project's main-domain document root.
     */
    public function resolvePath(): string
    {
        if (is_string($this->path) && $this->path !== '') {
            return $this->homePath($this->path);
        }

        $domain = $this->user->getMainDomain();
        if ($domain === null) {
            throw new \RuntimeException("Project '{$this->user->username}' has no main domain.");
        }

        return $this->absoluteDocumentRoot($domain);
    }

    /**
     * Absolute WP-CLI --path for a specific domain's document root
     * (used by the deprecated domain:wp-cli adapter).
     */
    public function resolveDomainPath(Domain $domain): string
    {
        return $this->absoluteDocumentRoot($domain);
    }

    /**
     * Adds --path unless the args carry one: in a container the bound path as
     * the container sees it, or none, so the runner finds the install.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function mergePath(array $args): array
    {
        foreach ($args as $arg) {
            if (is_string($arg) && Str::startsWith($arg, '--path=')) {
                return $args;
            }
        }

        if ($this->runsInContainer()) {
            if (is_string($this->path) && $this->path !== '') {
                $args[] = '--path='.$this->path;
            }

            return $args;
        }

        $args[] = '--path='.$this->resolvePath();

        return $args;
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    private function withSkips(array $args): array
    {
        if (! $this->skipPlugins) {
            return $args;
        }

        foreach (['--skip-plugins', '--skip-themes'] as $flag) {
            if (! in_array($flag, $args, true)) {
                $args[] = $flag;
            }
        }

        return $args;
    }

    /** Whether every run adds --skip-plugins --skip-themes. */
    public function skipsPlugins(): bool
    {
        return $this->skipPlugins;
    }

    /** Whether the PHP hosting install directory holds this file, as the account sees it. */
    public function installHasFile(string $relativePath): bool
    {
        return $this->user->project()->fileManager()->isFile(
            rtrim($this->resolvePath(), '/').'/'.ltrim($relativePath, '/'),
        );
    }

    /** WordPress in the project's own containers rather than on PHP hosting. */
    public function runsInContainer(): bool
    {
        return Projects::isDind($this->user);
    }

    private function homePath(string $path): string
    {
        try {
            return rtrim($this->user->project()->resolvePath($path), '/');
        } catch (ValidationException) {
            throw new Exception("The path {$path} does not name a directory inside the project home.");
        }
    }

    /** WP-CLI's container mounts the home at its own path, and at its working directory /var/www. */
    private function homeFile(string $field, string $file): string
    {
        $home = rtrim($this->user->project()->homeDirPath(), '/');
        $path = self::normalised(str_starts_with($file, '/') ? $file : self::PHP_HOSTING_WORKING_DIR.'/'.$file);
        if ($path !== null && self::isBelow($path, self::PHP_HOSTING_WORKING_DIR)) {
            $path = $home.substr($path, strlen(self::PHP_HOSTING_WORKING_DIR));
        }
        if ($path === null || ! self::isBelow($path, $home)) {
            throw self::outsideProject($field, "The {$field} must be inside the project home {$home}, absolute or relative to it; {$file} is not.");
        }

        return $path;
    }

    private function containerFile(string $field, string $file): string
    {
        $path = self::normalised($file);
        $relative = $path !== null && $path !== '' && ! str_starts_with($path, '/');
        if ($path === null || (! $relative && ! self::isBelow($path, self::CONTAINER_PROJECT_DIR))) {
            throw self::outsideProject($field, "The {$field} must be below ".self::CONTAINER_PROJECT_DIR
                .", where the project is mounted in its container, or relative to the WordPress directory; {$file} is neither.");
        }

        return $path;
    }

    /** `.` and `..` resolved by name; null when `..` would climb above where the path starts. */
    private static function normalised(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part !== '..') {
                $parts[] = $part;

                continue;
            }
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
        }

        return (str_starts_with($path, '/') ? '/' : '').implode('/', $parts);
    }

    /** Strictly below, by whole segments: `/home/alice2` is not below `/home/alice`. */
    private static function isBelow(string $path, string $dir): bool
    {
        return str_starts_with($path, rtrim($dir, '/').'/');
    }

    private static function outsideProject(string $field, string $message): ProblemException
    {
        return ProblemException::one($field, $field.'_outside_project', $message);
    }

    private function absoluteDocumentRoot(Domain $domain): string
    {
        return $this->user->project()->homeDirPath().$domain->getDocumentRoot();
    }

    private function system(): System
    {
        return $this->system ??= new System;
    }

    /**
     * Shared side effect for named cache flush and raw `cache flush`: after a
     * successful flush on OpenLiteSpeed, schedule a webserver reload. Used by
     * run() so both paths stay one implementation.
     *
     * @param  list<string>  $args
     * @param  array{exit_code?: int, stdout?: string, stderr?: string}  $result
     */
    private function scheduleReloadAfterCacheFlush(array $args, array $result): void
    {
        try {
            $cachePos = null;
            foreach ($args as $i => $a) {
                if ($a === 'cache') {
                    $cachePos = $i;
                    break;
                }
            }
            if ($cachePos === null || ! isset($args[$cachePos + 1]) || $args[$cachePos + 1] !== 'flush') {
                return;
            }
            if (($result['exit_code'] ?? 1) !== 0) {
                return;
            }

            $system = $this->system();
            if ($system->webserver()->getCurrentWebserver() === 'openlitespeed') {
                $system->webserver()->scheduleWebserverReloadInBackground();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to schedule webserver reload after wp cache flush', [
                'username' => $this->user->username,
                'args' => $args,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
