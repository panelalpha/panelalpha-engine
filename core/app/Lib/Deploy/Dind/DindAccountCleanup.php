<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\ImageTransfer;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\SafeName;
use App\System\Privileged;
use App\System\Privileged\Arguments;
use App\System\Privileged\InvalidArgument;

/**
 * Reclaim Docker-related disk when a DinD account is removed (host build cache, legacy pa-nm-* volume,
 * ~/docker) in the host's namespaces, keeping what a sibling account's compose file still declares.
 */
class DindAccountCleanup
{
    /**
     * The requests that reclaim an account's Docker disk: its volume and sidecar images through the host's
     * daemon, then its caches and ~/docker on disk.
     *
     * @param  list<string>  $sidecarRefs  host images this account pulled that
     *                                     are safe to remove (already filtered)
     * @return list<list<string>>
     */
    public static function hostCleanupArgvs(
        string $username,
        string $homeDir,
        array $sidecarRefs = []
    ): array {
        SafeName::assert($username, 'username for Docker cleanup');
        if (! preg_match('#^/home/[a-zA-Z0-9_.-]+$#', rtrim($homeDir, '/'))) {
            throw new \InvalidArgumentException('Refusing Docker cleanup outside /home/{user}');
        }

        return [
            Privileged::host()->removeAccountDockerStore(
                HostNodeBuild::nodeModulesVolumeName($username),
                self::safeImageRefs($sidecarRefs),
            ),
            Privileged::host()->removeAccountDockerFiles(
                ProjectCache::dirFor($username),
                ProjectCache::legacyDirsFor($username),
                rtrim($homeDir, '/').'/docker',
            ),
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return list<string>
     */
    private static function safeImageRefs(array $refs): array
    {
        $safe = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && ImageTransfer::isSafeImageRef($ref) && self::isRootImageArgument($ref)) {
                $safe[] = $ref;
            }
        }

        return $safe;
    }

    /** An uppercase repository is skipped, since the gateway refuses it. */
    private static function isRootImageArgument(string $ref): bool
    {
        try {
            Arguments::image($ref);

            return true;
        } catch (InvalidArgument) {
            return false;
        }
    }

    /**
     * Every `image:` line the other accounts declare, so their shared host cache entry survives this
     * deletion. Grep, not a YAML parse: in a keep-list a stray match only skips an `rmi`.
     *
     * @return list<string>
     */
    public static function otherAccountComposeImagesArgv(
        string $usersHomeDir,
        string $excludeUsername
    ): array {
        SafeName::assert($excludeUsername, 'username for Docker cleanup');
        $base = rtrim($usersHomeDir, '/');
        if (! preg_match('#^(/[a-zA-Z0-9_.-]+)+$#', $base)) {
            throw new \InvalidArgumentException('Refusing to scan an unexpected home root');
        }

        return Privileged::scripts()->composeImageLines($base, $excludeUsername, self::composeFileNames());
    }

    /**
     * The client's own compose file names plus the engine's reserved run-file names, under which alone
     * a recipe deploy's images live.
     *
     * @return list<string>
     */
    private static function composeFileNames(): array
    {
        return [
            ...ComposeFileInspector::COMPOSE_FILE_CANDIDATES,
            EngineArtifacts::RUN_COMPOSE,
            EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            EngineArtifacts::RUN_CLIENT_OVERRIDE,
            EngineArtifacts::APP_CONFIG_COMPOSE,
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
            if ($ref !== null && ! in_array($ref, $refs, true)) {
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
            if ($ref !== null && ! in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Host images this account pulled as sidecars, except the prewarm catalog, PanelAlpha's own images,
     * and anything a host container or another account's compose file still declares.
     *
     * @param  list<string>  $accountImageRefs
     * @param  list<string>  $stillNeeded
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
            if (! is_string($ref) || $ref === '' || ! ImageTransfer::isSafeImageRef($ref)) {
                continue;
            }
            if (isset($keep[$ref]) || isset($protected[$ref]) || self::isHostInfrastructure($ref)) {
                continue;
            }
            if (! in_array($ref, $remove, true)) {
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
     * Host infrastructure, not this account's: the engine's published set and everything it builds, kept
     * even unreferenced, as their lifecycle is HostPrewarmPlan's, not teardown's.
     */
    private static function isHostInfrastructure(string $ref): bool
    {
        return str_starts_with($ref, 'ghcr.io/panelalpha/')
            || BuiltImage::runtimeFor($ref) !== null;
    }

    /**
     * Everything the caching system might have put on the host, not just the account-seed set.
     *
     * @return array<string, true>
     */
    private static function protectedHostImages(): array
    {
        $refs = [];
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
