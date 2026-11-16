<?php

namespace App\Lib\Deploy;

use App\System\Privileged;

/**
 * Everything the host caches for a project, project-first under `/var/cache/panelalpha/projects/<username>/<cache>`,
 * so teardown is one `rm -rf`. Changing a mount path inside a build needs `recipe:` bumped in images.yaml.
 */
final class ProjectCache
{
    public const ROOT = '/var/cache/panelalpha/projects';

    /** The pre-project-scoped locations, kept only so an upgrading host can move what it has. */
    public const LEGACY_JS_ROOT = '/var/cache/panelalpha/js';

    public const LEGACY_COMPOSER_ROOT = '/var/cache/pa-composer';

    /**
     * Every root a project cache can be sitting in, new layout first.
     *
     * @var list<string>
     */
    public const ROOTS = [self::ROOT, self::LEGACY_JS_ROOT, self::LEGACY_COMPOSER_ROOT];

    public const COMPOSER = 'composer';

    public const NODE_MODULES = 'node_modules';

    /**
     * Every cache a project can accumulate, each a directory the build writes in. Adding one here is all that is
     * needed. The project's directory itself stays root's.
     *
     * @var list<string>
     */
    public const NAMES = ['node_modules', 'npm', 'pnpm', 'yarn', 'bun', self::COMPOSER, 'pip', 'go', 'cargo', 'm2', 'maven', 'gradle'];

    /** The project's cache directory, the only thing teardown removes. */
    public static function dirFor(string $username): string
    {
        SafeName::assert($username, 'username for project cache');

        return self::ROOT.'/'.$username;
    }

    /** One named cache inside it, checked against {@see NAMES}: these strings reach `rm -rf` and a bind mount. */
    public static function subdirFor(string $username, string $name): string
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new \InvalidArgumentException('Unknown project cache: '.$name);
        }

        return self::dirFor($username).'/'.$name;
    }

    /**
     * Where this project's caches used to live.
     *
     * @return list<string>
     */
    public static function legacyDirsFor(string $username): array
    {
        SafeName::assert($username, 'username for project cache');

        return [
            self::LEGACY_JS_ROOT.'/'.$username,
            self::LEGACY_COMPOSER_ROOT.'/'.$username,
        ];
    }

    /**
     * A bad name to skip is refused here too, so it is a bug report rather than ignored downstream.
     *
     * @param  list<string>  $skipUsernames  accounts with a deploy in flight
     * @return list<string>
     */
    public static function pruneArgv(int $maxAgeSeconds, bool $dryRun = false, array $skipUsernames = []): array
    {
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('Cache age cannot be negative');
        }

        $skip = [];
        foreach ($skipUsernames as $username) {
            SafeName::assert($username, 'username to skip');
            $skip[] = $username;
        }

        return Privileged::host()->pruneProjectCaches($maxAgeSeconds, $dryRun, $skip);
    }

    /**
     * The script's report: one TAB-separated record per stale cache.
     *
     * @return list<array{path: string, bytes: int, age: int, action: string}>
     */
    public static function parseReport(string $output): array
    {
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($output)) ?: [] as $line) {
            $parts = explode("\t", trim($line));
            if (count($parts) !== 4 || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
                continue;
            }
            $rows[] = [
                'path' => $parts[0],
                'bytes' => (int) $parts[1],
                'age' => (int) $parts[2],
                'action' => $parts[3],
            ];
        }

        return $rows;
    }

    /**
     * Accept "24h", "7d", "90m", "3600". Null for an unset option.
     */
    public static function parseDuration(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d+)\s*([smhdw]?)$/i', $value, $m) !== 1) {
            throw new \InvalidArgumentException("Not a duration: {$value}");
        }
        $unit = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];

        return ((int) $m[1]) * $unit[strtolower($m[2])];
    }
}
