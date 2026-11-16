<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Exceptions\ProblemException;
use App\Lib\Project\ClassicAppType;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Projects;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Raw WP-CLI invocation for a project's WordPress install, at the path bound on construction or, when null, the main
 * domain's document root on PHP hosting or the install a container's runner finds; a compose service may be bound.
 */
final class WpCli
{
    /** Where the engine's WordPress mounts the project's checkout in its container. */
    private const CONTAINER_PROJECT_DIR = '/var/www/html';

    /** On PHP hosting, WP-CLI's working directory: the home, mounted a second time. */
    private const PHP_HOSTING_WORKING_DIR = '/var/www';

    /** Run by WP-CLI before its command, so a file the command creates is its owner's alone from the start. */
    private const OWNER_ONLY_FILES = '--exec=umask(0077);';

    /**
     * Flags for the PHP that runs WP-CLI: reading wp-config.php, it exhausts PCRE's JIT stack on a value of 4095 bytes
     * and its recursion depth on a longer one, and then lists no entry at all.
     */
    public const PHP_FLAGS = ['-d', 'pcre.jit=0', '-d', 'pcre.recursion_limit=1000000'];

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
        $result = $this->project()->runWpCli($args, $this->service);
        $this->scheduleReloadAfterCacheFlush($args, $result);

        return $result;
    }

    /**
     * Run WP-CLI and throw on a non-zero exit, so a named operation fails as an exception (HTTP error), never as a 200
     * body with exit_code; the raw command keeps run().
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
     * A caller's file as WP-CLI is to get it: on PHP hosting absolute in the project home, in its containers below the
     * checkout's mount or relative to the install, WP-CLI's working dir; `..` is resolved first, so it cannot lead out.
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
            $chunk = trim(self::withoutControlCharacters((string) ($result[$key] ?? '')));
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return $parts !== [] ? implode("\n", $parts) : 'WP-CLI command failed.';
    }

    /** Drops terminal controls such as the bell mysqldump rings before an error, keeping line breaks and tabs. */
    private static function withoutControlCharacters(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text);
    }

    /**
     * Absolute WP-CLI --path inside the project home: the bound path read as the file manager reads one (absolute or
     * relative to the home), else the directory WordPress was installed into, else the main-domain docroot.
     */
    public function resolvePath(): string
    {
        if (is_string($this->path) && $this->path !== '') {
            return $this->homePath($this->path);
        }

        $installed = ClassicAppType::installPathOf($this->user);
        if ($installed !== null) {
            return $installed;
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
     * Adds --path unless the args carry one, as PhpRuntime::runWpCli does: in a container the bound path as the container
     * sees it, absolute or relative to its working directory, or none, so the runner finds the install.
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
     * A fatal error in one plugin or theme then blocks no operation that does not need them loaded.
     *
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
        return $this->project()->fileManager()->isFile(self::installPath($relativePath));
    }

    /**
     * On PHP hosting no other account can read the file at any moment: umask 077, and a file `--force` replaces is
     * made 600 before the write and after. A container project keeps its image's mode.
     *
     * @param  list<string>  $options
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function createConfigFile(array $options): array
    {
        if ($this->runsInContainer()) {
            return $this->runOrFail(['config', 'create', ...$options]);
        }

        if (in_array('--force', $options, true) && $this->installHasFile('wp-config.php')) {
            $this->restrictConfigFile();
        }
        $result = $this->runOrFail(['config', 'create', ...$options, self::OWNER_ONLY_FILES]);
        $this->restrictConfigFile();

        return $result;
    }

    private function restrictConfigFile(): void
    {
        $this->project()->fileManager()->chmod($this->installPath('wp-config.php'), '600');
    }

    private function installPath(string $relativePath): string
    {
        return rtrim($this->resolvePath(), '/').'/'.ltrim($relativePath, '/');
    }

    /** WordPress in the project's own containers rather than on PHP hosting. */
    public function runsInContainer(): bool
    {
        return Projects::isDind($this->user);
    }

    /**
     * The absolute directory WordPress is installed in on PHP hosting; null in the project's own containers, where the
     * runner finds it.
     */
    public function installedPath(): ?string
    {
        return $this->runsInContainer() ? null : $this->resolvePath();
    }

    private function homePath(string $path): string
    {
        try {
            return rtrim($this->project()->resolvePath($path), '/');
        } catch (ValidationException) {
            throw new Exception("The path {$path} does not name a directory inside the project home.");
        }
    }

    /** WP-CLI's container mounts the home at its own path, and at its working directory /var/www. */
    private function homeFile(string $field, string $file): string
    {
        $home = rtrim($this->project()->homeDirPath(), '/');
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
        return $this->project()->homeDirPath().$domain->getDocumentRoot();
    }

    /** A System given on construction fakes the webserver in tests; production builds one on demand. */
    private function system(): System
    {
        return $this->system ??= new System;
    }

    private function project(): Project
    {
        return $this->user->project($this->system());
    }

    /**
     * After a named or raw `cache flush` exits 0 on OpenLiteSpeed, schedule a background webserver reload, from run() so
     * both paths share it. A failure to schedule is logged and does not fail the call.
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
