<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\SafeName;

/**
 * Reclaim Docker-related disk when a DinD account is removed: host build cache,
 * legacy pa-nm-* volume and ~/docker, via nsenter pid 1, keeping what a sibling
 * account's compose file still declares.
 */
class DindAccountCleanup
{
    /**
     * @param list<string> $sidecarRefs host images this account pulled that
     *                                  are safe to remove (already filtered)
     * @return list<string>
     */
    public static function hostCleanupArgv(
        string $username,
        string $homeDir,
        array $sidecarRefs = []
    ): array {
        SafeName::assert($username, 'username for Docker cleanup');
        if (!preg_match('#^/home/[a-zA-Z0-9_.-]+$#', rtrim($homeDir, '/'))) {
            throw new \InvalidArgumentException('Refusing Docker cleanup outside /home/{user}');
        }

        $volume = HostNodeBuild::nodeModulesVolumeName($username);
        // One directory holds every cache this project accumulated; a new cache
        // type is a name in ProjectCache::NAMES.
        $projectCache = ProjectCache::dirFor($username);
        $dockerDir = rtrim($homeDir, '/') . '/docker';

        // Pre-project-scoped directories, for an account deleted before it
        // redeployed onto the current layout.
        $legacy = array_map('escapeshellarg', ProjectCache::legacyDirsFor($username));

        $script = 'docker volume rm -f ' . escapeshellarg($volume) . ' 2>/dev/null || true; '
            . 'rm -rf ' . escapeshellarg($projectCache)
            . ' ' . implode(' ', $legacy)
            . ' ' . escapeshellarg($dockerDir);

        $rmi = [];
        foreach ($sidecarRefs as $ref) {
            if (is_string($ref) && ImageTransfer::isSafeImageRef($ref)) {
                $rmi[] = escapeshellarg($ref);
            }
        }
        // No -f: the daemon decides whether a stopped container still holds it.
        if ($rmi !== []) {
            $script .= '; docker rmi ' . implode(' ', $rmi) . ' 2>/dev/null || true';
        }

        return [
            'sudo',
            'nsenter',
            '--target',
            '1',
            '--all',
            'sh',
            '-c',
            $script,
        ];
    }

    /**
     * Every `image:` line the *other* accounts declare, so their shared host
     * cache entry survives this deletion. Grep, not a YAML parse: this is a
     * keep-list, where a stray match only skips an `rmi`.
     *
     * @return list<string>
     */
    public static function otherAccountComposeImagesArgv(
        string $usersHomeDir,
        string $excludeUsername
    ): array {
        SafeName::assert($excludeUsername, 'username for Docker cleanup');
        $base = rtrim($usersHomeDir, '/');
        if (!preg_match('#^(/[a-zA-Z0-9_.-]+)+$#', $base)) {
            throw new \InvalidArgumentException('Refusing to scan an unexpected home root');
        }

        // The client's own candidate names (their compose file, still read for
        // its `image:` lines by a compose-strategy project) plus the engine's
        // reserved run-file names — a recipe deploy's images live only under
        // the latter, never under a name the client would recognise.
        $names = [
            ...ComposeFileInspector::COMPOSE_FILE_CANDIDATES,
            EngineArtifacts::RUN_COMPOSE,
            EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            EngineArtifacts::APP_CONFIG_COMPOSE,
        ];
        $globs = [];
        foreach ($names as $candidate) {
            $globs[] = escapeshellarg($base) . '/*/project/' . $candidate;
        }

        return [
            'sudo',
            'sh',
            '-c',
            'for f in ' . implode(' ', $globs) . '; do '
                . 'case "$f" in ' . escapeshellarg($base . '/' . $excludeUsername) . '/*) continue ;; esac; '
                . '[ -f "$f" ] || continue; '
                . 'grep -hE \'^[[:space:]]*image:[[:space:]]*[^[:space:]]\' "$f"; '
                . 'done 2>/dev/null || true',
        ];
    }

    /**
     * Turn `  image: "mysql:8.4"` lines into refs the host would recognise.
     *
     * @return list<string>
     */
    public static function parseComposeImageLines(string $output): array
    {
        $refs = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $value = preg_replace('/^\s*image:\s*/', '', $line);
            $ref = ImageTransfer::normalizeImageRef(trim((string) $value, " \t\"'"));
            if ($ref !== null && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Refs the host would recognise, from one-ref-per-line output (`docker
     * images`, `docker ps --format {{.Image}}`).
     *
     * @return list<string>
     */
    public static function parseImageRefLines(string $output): array
    {
        $refs = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($output)) ?: [] as $line) {
            $ref = ImageTransfer::normalizeImageRef(trim($line));
            if ($ref !== null && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Host images this account pulled as sidecars, excluding the prewarm
     * catalog, PanelAlpha engine/app images, and anything a host container or
     * another account's compose file still declares.
     *
     * @param list<string> $accountImageRefs
     * @param list<string> $stillNeeded
     * @return list<string>
     */
    public static function hostSidecarRefsToRemove(
        array $accountImageRefs,
        array $stillNeeded = []
    ): array {
        $keep = array_flip($stillNeeded);
        $protected = self::protectedHostImages();
        $remove = [];
        foreach ($accountImageRefs as $ref) {
            if (!is_string($ref) || $ref === '' || !ImageTransfer::isSafeImageRef($ref)) {
                continue;
            }
            if (isset($keep[$ref]) || isset($protected[$ref]) || self::isHostInfrastructure($ref)) {
                continue;
            }
            if (!in_array($ref, $remove, true)) {
                $remove[] = $ref;
            }
        }

        return $remove;
    }

    public static function isProtectedHostImage(string $ref): bool
    {
        return self::isHostInfrastructure($ref) || isset(self::protectedHostImages()[$ref]);
    }

    /**
     * Images that are host infrastructure, not this account's: the
     * engine's published set, and everything it builds. Protected even when no
     * account references them, since nothing publishes these and a rebuild
     * costs 30-150s; their lifecycle is HostPrewarmPlan's, not teardown's.
     */
    private static function isHostInfrastructure(string $ref): bool
    {
        return str_starts_with($ref, 'ghcr.io/panelalpha/')
            || BuiltImage::runtimeFor($ref) !== null;
    }

    /**
     * @return array<string, true>
     */
    private static function protectedHostImages(): array
    {
        $refs = [];
        // Everything the caching system might have put on the host, not just
        // the account-seed set.
        foreach (ImageCatalog::all() as $seed) {
            $refs[$seed] = true;
        }
        foreach (HostPrewarmPlan::catalog() as $item) {
            $ref = $item['ref'] ?? null;
            if (is_string($ref)) {
                $refs[$ref] = true;
            }
        }

        return $refs;
    }
}
