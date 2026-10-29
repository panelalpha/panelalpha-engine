<?php

namespace App\Lib\Deploy;

use App\Lib\Deploy\Dind\DindHostBuilder;
use Tests\Unit\Deploy\PruneProjectCachesScriptTest;

/**
 * Everything the host caches on a project's behalf, under one directory per
 * project.
 *
 * The layout is `/var/cache/panelalpha/projects/<username>/<cache>`, and the
 * ordering is the whole point. It used to be cache-first -- the JS caches at
 * `/var/cache/panelalpha/js/<username>`, Composer's at
 * `/var/cache/pa-composer/<username>` -- which meant every question about a
 * project ("how much is it caching?", "delete it") had to enumerate the cache
 * types, and every new cache type had to be added to each of those places.
 *
 * That is not hypothetical: account teardown removed the JS cache and never
 * learned about Composer's, so deleting a PHP account leaked ~19MB
 * permanently. Project-first makes teardown one `rm -rf` of one directory,
 * which cannot miss a cache that did not exist when it was written, and makes
 * a Ruby or Python cache a matter of adding a name to {@see NAMES} rather
 * than touching cleanup at all.
 *
 * Only the *host* side moves. What these directories are mounted at inside a
 * build container is unchanged and deliberately so: `/var/cache/pa-composer`
 * is baked into the shared PHP base as `ENV COMPOSER_CACHE_DIR`, and that
 * image's tag carries a date somebody bumps rather than a hash of what went
 * into it -- so changing this constant alters the image's contents without
 * changing its name, and every host keeps serving the old one. Change it and
 * bump `recipe:` in `config/core/images.yaml` in the same edit.
 *
 * No Laravel dependencies -- unit-testable.
 */
final class ProjectCache
{
    public const ROOT = '/var/cache/panelalpha/projects';

    /**
     * The pre-project-scoped locations, kept only so a host that upgrades
     * mid-life can move what it already has. {@see migrationScript()}.
     */
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
     * Every cache a project can accumulate. Adding one here is all that is
     * needed: it is created with the rest and removed with the directory.
     *
     * @var list<string>
     */
    public const NAMES = ['node_modules', 'npm', 'pnpm', 'yarn', 'bun', self::COMPOSER];

    /**
     * The project's cache directory -- the single thing teardown removes.
     */
    public static function dirFor(string $username): string
    {
        SafeName::assert($username, 'username for project cache');

        return self::ROOT.'/'.$username;
    }

    /**
     * One named cache inside it.
     *
     * The name is checked against {@see NAMES} rather than merely being made
     * safe: these strings reach `rm -rf` and a bind mount, and a typo that
     * silently created a directory nothing else knows about is exactly the
     * class of bug this layout exists to prevent.
     */
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
     * Create the project's caches and hand them to its uid:gid, moving
     * anything the old layout left behind on the way.
     *
     * The move is worth making rather than starting cold: these caches are
     * what keep a redeploy off packagist and the npm registry, and an upgrade
     * that silently discarded them would make the first deploy after it look
     * like a regression. It happens at most once -- afterwards the legacy
     * directories are gone -- and every step is allowed to fail, because a
     * cache is an optimisation and {@see \App\\System\\Project\Dind\HostCompile}
     * already treats a host that will not provide one as a slower deploy
     * rather than a failed one.
     *
     * `mv` per entry rather than of the JS directory itself: the destination
     * already exists by then, and `mv dir dest/` would nest it inside.
     *
     * The `touch` is what makes {@see staleUsernames()} possible, and it has
     * to be explicit. `mkdir -p` on a directory that exists changes nothing,
     * `chown` moves ctime rather than mtime, and the most valuable deploy of
     * all -- the one that hits the node_modules cache and skips installing --
     * only *reads*. Without this the directory's mtime would say when the
     * cache was first created and a busy project would look abandoned.
     */
    public static function prepareScript(string $username, string $identity): string
    {
        if (preg_match('/^\d+:\d+\z/', $identity) !== 1) {
            throw new \InvalidArgumentException('Invalid cache owner identity');
        }

        $dir = escapeshellarg(self::dirFor($username));
        [$legacyJs, $legacyComposer] = array_map('escapeshellarg', self::legacyDirsFor($username));

        $subdirs = [];
        foreach (self::NAMES as $name) {
            $subdirs[] = escapeshellarg(self::subdirFor($username, $name));
        }

        return 'mkdir -p '.$dir
            .'; touch '.$dir
            .'; if [ -d '.$legacyJs.' ]; then '
            .'mv '.$legacyJs.'/* '.$dir.'/ 2>/dev/null; '
            .'rmdir '.$legacyJs.' 2>/dev/null; fi'
            .'; if [ -d '.$legacyComposer.' ] && [ ! -d '.$dir.'/'.self::COMPOSER.' ]; then '
            .'mv '.$legacyComposer.' '.$dir.'/'.self::COMPOSER.' 2>/dev/null; fi'
            .'; mkdir -p '.implode(' ', $subdirs)
            .' && chown -R '.escapeshellarg($identity).' '.$dir
            .' && chmod 750 '.$dir;
    }

    /**
     * The script that prunes stale caches, and the whole of what it does.
     *
     * A shell script rather than PHP because the core container cannot see
     * these directories at all -- /var/cache/panelalpha is not a container
     * volume, which is why {@see DindHostBuilder::prepareCacheArgv()}
     * enters the host namespace to create them. `scandir` and `unlink` have
     * nothing to work on from in here.
     *
     * And a script on disk rather than a command assembled here: scanning
     * three roots, filtering names, comparing ages and sizing directories is a
     * real program, and a real program pasted together from PHP string
     * fragments is unreadable and cannot be run on its own. This one takes
     * arguments, is executable by hand, and is tested by running it against a
     * temporary tree ({@see PruneProjectCachesScriptTest}).
     */
    public const PRUNE_SCRIPT = '/opt/panelalpha/shared-hosting/scripts/prune-project-caches.sh';

    /**
     * @param  list<string>  $skipUsernames  accounts with a deploy in flight,
     *                                       whose caches are about to be read
     * @return list<string>
     */
    public static function pruneArgv(int $maxAgeSeconds, bool $dryRun = false, array $skipUsernames = []): array
    {
        if ($maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('Cache age cannot be negative');
        }

        $argv = ['sudo', 'nsenter', '--target', '1', '--all', 'sh', self::PRUNE_SCRIPT, (string) $maxAgeSeconds];
        if ($dryRun) {
            $argv[] = '--dry-run';
        }

        $skip = [];
        foreach ($skipUsernames as $username) {
            // The script checks these too; refusing here means a bad name is a
            // bug report rather than something quietly ignored downstream.
            SafeName::assert($username, 'username to skip');
            $skip[] = $username;
        }
        if ($skip !== []) {
            $argv[] = '--skip';
            $argv[] = implode(',', $skip);
        }

        return $argv;
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
