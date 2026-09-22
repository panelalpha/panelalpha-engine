<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use Symfony\Component\Yaml\Yaml;

/**
 * What kind of compose file is this: a live hosting stack, a workstation
 * bind-mount setup that only runs on a laptop, a documentation template the
 * repo ships but nothing loads, or engine-written bootstrap output.
 *
 * Read by {@see DetectProjectStrategy} to decide whether a compose file wins
 * the deploy strategy, and by the runtime-sidecar readers to decide whether a
 * compose file is a source of backing services rather than a thing to run.
 *
 * No Laravel dependencies — unit-testable with a temp directory.
 */
class ComposeFileInspector
{
    /**
     * Host-user mapping vars. Templates pass these from the laptop; they are
     * empty in hosting and `groupadd -g` exits 3.
     *
     * `UID`/`GID` are here because `$uid` is what a hand-written Dockerfile
     * tends to call it, and matched case-insensitively for the same reason:
     * Crater's is `ARG uid` / `useradd -u $uid`, and missing it meant the file
     * was accepted as deployable and then died on `useradd: invalid user ID
     * '-d'` once the empty argument shifted the rest of the command along.
     */
    private const HOST_UID_VARS = 'WWWGROUP|WWWUSER|PUID|PGID|USER_ID|GROUP_ID|HOST_UID|HOST_GID|UID|GID';

    /**
     * Docker Compose V2 filename priority.
     *
     * @var list<string>
     */
    public const COMPOSE_FILE_CANDIDATES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yml',
        'docker-compose.yaml',
    ];

    /**
     * Compose files a repo ships as a template rather than as a live stack.
     *
     * A widespread convention: the author documents the full stack - app,
     * database, cache - and expects the operator to copy the file and fill in
     * secrets. Nothing loads these automatically, so a repo like this looks to
     * us like an app with no database, and the deploy ends in "connection
     * refused" that a non-technical customer cannot act on. They are read for
     * their runtime services only; the file itself is never run.
     */
    public const COMPOSE_EXAMPLE_CANDIDATES = [
        'compose.example.yml',
        'compose.example.yaml',
        'docker-compose.example.yml',
        'docker-compose.example.yaml',
        'compose.sample.yml',
        'docker-compose.sample.yml',
        'compose.yml.example',
        'docker-compose.yml.example',
        'docker-compose.yml.dist',
        'compose.yml.dist',
        // Stack slices named by engine (Quenti ships docker-compose.mysql.yml).
        'docker-compose.mysql.yml',
        'docker-compose.mysql.yaml',
        'docker-compose.postgres.yml',
        'docker-compose.postgres.yaml',
        'docker-compose.db.yml',
        'docker-compose.db.yaml',
        'compose.mysql.yml',
        'compose.postgres.yml',
        'compose.db.yml',
    ];

    /**
     * Suffix for compose files moved aside so docker compose V2 does not
     * prefer them over the hosting `docker-compose.yml`.
     */
    public const COMPOSE_STASH_SUFFIX = '.panelalpha-local';

    /**
     * The compose file a project ships, by the conventional names in
     * preference order, or null when it ships none.
     */
    public static function firstIn(string $projectDir): ?string
    {
        $root = rtrim($projectDir, '/');
        foreach (self::COMPOSE_FILE_CANDIDATES as $candidate) {
            $path = $root . '/' . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Compose filenames in $projectDir that docker compose would load instead
     * of $keep (V2 searches compose.yaml before docker-compose.yml).
     *
     * @return list<string>
     */
    public static function composeFilesThatShadow(string $projectDir, string $keep = 'docker-compose.yml'): array
    {
        $projectDir = rtrim($projectDir, '/');
        $keep = strtolower($keep);
        $found = [];
        foreach (self::COMPOSE_FILE_CANDIDATES as $name) {
            if (strtolower($name) === $keep) {
                continue;
            }
            if (is_file($projectDir . '/' . $name)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Compose-spec Dockerfile paths that are missing on disk.
     *
     * `dockerfile:` is relative to `build.context`, not the repo root.
     * `context: ./console` + `dockerfile: Dockerfile` → `console/Dockerfile`.
     *
     * @return list<string> project-relative paths (for error messages)
     */
    public static function missingComposeDockerfileRefs(string $composePath, string $projectDir): array
    {
        $missing = [];
        foreach (self::composeBuildDockerfileRefs($composePath, $projectDir) as $ref) {
            if (!is_file($ref['absolute'])) {
                $missing[] = $ref['relative'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Absolute Dockerfile a compose `build:` would use, or null when there
     * is no on-disk file to check (inline Dockerfile, git/HTTP context).
     *
     * @param array<string, mixed>|string $build
     */
    public static function composeBuildDockerfileAbsolute(string $projectDir, array|string $build): ?string
    {
        $resolved = self::resolveComposeBuildDockerfile($projectDir, $build);

        return $resolved['absolute'] ?? null;
    }

    /**
     * True when the compose build context is the project root (`.`).
     *
     * @param array<string, mixed>|string $build
     */
    public static function composeBuildContextIsProjectRoot(array|string $build): bool
    {
        if (is_string($build)) {
            $context = $build;
        } else {
            $context = $build['context'] ?? '.';
            if (!is_string($context)) {
                return false;
            }
        }

        $context = str_replace('\\', '/', trim($context));

        return $context === '' || $context === '.' || $context === './';
    }

    /**
     * @return list<array{relative: string, absolute: string}>
     */
    private static function composeBuildDockerfileRefs(string $composePath, string $projectDir): array
    {
        if (!is_file($composePath) || !is_readable($composePath)) {
            return [];
        }
        $raw = @file_get_contents($composePath);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $parsed = ComposeYaml::parse($raw);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return [];
        }

        $refs = [];
        foreach ($parsed['services'] as $service) {
            if (!is_array($service) || !isset($service['build'])) {
                continue;
            }
            $build = $service['build'];
            if (!is_string($build) && !is_array($build)) {
                continue;
            }
            $resolved = self::resolveComposeBuildDockerfile($projectDir, $build);
            if ($resolved === null) {
                continue;
            }
            $refs[] = $resolved;
        }

        return $refs;
    }

    /**
     * @param array<string, mixed>|string $build
     * @return array{relative: string, absolute: string}|null
     */
    private static function resolveComposeBuildDockerfile(string $projectDir, array|string $build): ?array
    {
        if (is_string($build)) {
            $context = $build;
            $dockerfile = 'Dockerfile';
        } else {
            if (isset($build['dockerfile_inline'])) {
                return null;
            }
            $context = $build['context'] ?? '.';
            if (!is_string($context) || $context === '') {
                $context = '.';
            }
            $dockerfile = $build['dockerfile'] ?? 'Dockerfile';
            if (!is_string($dockerfile) || $dockerfile === '') {
                $dockerfile = 'Dockerfile';
            }
        }

        $context = str_replace('\\', '/', trim($context));
        $dockerfile = str_replace('\\', '/', trim($dockerfile));
        if ($context === '' || $dockerfile === '') {
            return null;
        }
        if (preg_match('#^(https?://|git@|ssh://)#i', $context) === 1) {
            return null;
        }
        if (str_contains($context, '..') || str_contains($dockerfile, '..')) {
            return null;
        }
        if (str_starts_with($dockerfile, '/')) {
            return [
                'relative' => $dockerfile,
                'absolute' => $dockerfile,
            ];
        }

        $contextRel = self::stripDotSlash($context);
        $fileRel = self::stripDotSlash($dockerfile);
        if ($contextRel === '' || $contextRel === '.') {
            $relative = $fileRel;
        } else {
            $relative = rtrim($contextRel, '/') . '/' . ltrim($fileRel, '/');
        }
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return [
            'relative' => $relative,
            'absolute' => rtrim($projectDir, '/') . '/' . ltrim($relative, '/'),
        ];
    }

    private static function stripDotSlash(string $path): string
    {
        if (str_starts_with($path, './')) {
            return substr($path, 2);
        }

        return $path;
    }

    /**
     * Local-dev compose: bind-mount of the repo plus a build, or build-args
     * that interpolate a host UID/GID without a default. Framework-agnostic —
     * Sail, Symfony Docker, Vite, and similar workstation stacks all match.
     */
    public static function isLocalDevCompose(string $composePath): bool
    {
        if (!is_file($composePath) || !is_readable($composePath)) {
            return false;
        }
        $raw = @file_get_contents($composePath);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return self::isLocalDevComposeYaml($raw);
    }

    public static function isLocalDevComposeYaml(string $raw): bool
    {
        return self::localDevComposeReasonYaml($raw) !== null;
    }

    /**
     * Why a file reads as a workstation dev compose, or null when it does not.
     * The boolean check and the deploy log share this, so a demotion can name
     * the service and mount that triggered it instead of leaving a silent gap.
     */
    public static function localDevComposeReason(string $composePath): ?string
    {
        if (!is_file($composePath) || !is_readable($composePath)) {
            return null;
        }
        $raw = @file_get_contents($composePath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return self::localDevComposeReasonYaml($raw);
    }

    public static function localDevComposeReasonYaml(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = ComposeYaml::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return null;
        }

        foreach ($parsed['services'] as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            if (self::serviceHasUndefaultedHostUidBuildArg($service)) {
                return 'service `' . (string) $name . '` maps a host UID/GID build arg';
            }
            if (isset($service['build']) && ($source = self::projectRootBindSource($service)) !== null) {
                $where = in_array($source, ['.', './', '${PWD}', '$PWD'], true)
                    ? 'the project root'
                    : '`' . $source . '`';

                return 'service `' . (string) $name . '` mounts ' . $where;
            }
        }

        return null;
    }

    /**
     * Compose file whose every service is a known datastore (postgres, redis, …).
     * Treat as sidecar inventory for framework/railpack deploys — not STRATEGY_COMPOSE.
     */
    public static function isSidecarsOnlyCompose(string $composePath): bool
    {
        if (!is_file($composePath) || !is_readable($composePath)) {
            return false;
        }
        $raw = @file_get_contents($composePath);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        return self::isSidecarsOnlyComposeYaml($raw);
    }

    public static function isSidecarsOnlyComposeYaml(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        try {
            $parsed = ComposeYaml::parse($raw);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($parsed) || !isset($parsed['services']) || !is_array($parsed['services'])) {
            return false;
        }

        $seen = 0;
        foreach ($parsed['services'] as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $seen++;
            if (isset($service['build'])) {
                return false;
            }
            if (self::isWorkstationAppService($service)) {
                return false;
            }
            // Datastores and the consoles that come with them. A file that is
            // only those is somebody's development environment -- Vendure's
            // ships five database servers, Elasticsearch, Redis, Keycloak,
            // Jaeger, Loki and Grafana, and not one line of Vendure -- so
            // deploying it would run a stack that contains no application.
            if (!SidecarEngine::isKnownDatastore((string) $name, $service)
                && !DevServices::isDevSidecar((string) $name, $service)
            ) {
                return false;
            }
        }

        return $seen > 0;
    }

    /**
     * The workstation app service (bind-mount of the repo and/or host UID
     * build args). Image-only sidecars (mysql, redis, typesense) are not this.
     *
     * @param array<string, mixed> $service
     */
    public static function isWorkstationAppService(array $service): bool
    {
        if (self::serviceHasUndefaultedHostUidBuildArg($service)) {
            return true;
        }

        return isset($service['build']) && self::serviceBindsProjectRoot($service);
    }

    /**
     * Dockerfile that maps a host UID/GID with no numeric ARG default.
     * Nested copies of local-dev runtimes (docker/8.x, docker/Dockerfile)
     * must not win over PHP/JS/Railpack recipes.
     */
    public static function isHostUidMappedDockerfile(string $path): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        // An entrypoint written as a heredoc reads $PUID at run time, not from a build ARG.
        $raw = \App\Lib\Deploy\Detect\DockerfileFinder::withoutHeredocBodies($raw);
        if (preg_match('/\b(groupadd|useradd|addgroup|adduser)\b/', $raw) !== 1) {
            return false;
        }
        if (preg_match('/\$(?:\{)?(' . self::HOST_UID_VARS . ')\b/i', $raw, $match) !== 1) {
            return false;
        }
        $var = $match[1];

        return preg_match('/^\s*ARG\s+' . preg_quote($var, '/') . '\s*=\s*\d+/mi', $raw) !== 1;
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function serviceHasUndefaultedHostUidBuildArg(array $service): bool
    {
        $build = $service['build'] ?? null;
        if (!is_array($build) || !isset($build['args'])) {
            return false;
        }
        $args = $build['args'];
        if (!is_array($args)) {
            return is_string($args) && self::stringHasUndefaultedHostUid($args);
        }
        foreach ($args as $value) {
            if (is_string($value) && self::stringHasUndefaultedHostUid($value)) {
                return true;
            }
        }

        return false;
    }

    private static function stringHasUndefaultedHostUid(string $value): bool
    {
        return preg_match('/\$\{(?:' . self::HOST_UID_VARS . ')\}/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function serviceBindsProjectRoot(array $service): bool
    {
        return self::projectRootBindSource($service) !== null;
    }

    /**
     * The bind source that marks a service a workstation app service, or null.
     *
     * The project root (`.`/`./`/`${PWD}`) always counts: the service builds
     * an image and then mounts the whole source over it, which is how a laptop
     * gets live reload and never how a deployment ships.
     *
     * A `./subdir` counts only when it is a read-write mount of a source tree
     * -- OpenCart 4.1 mounts `./upload`, its whole shop. It does NOT count when
     * it is read-only, or a single generated file: that is a recipe injecting
     * one artifact (an entrypoint/config/answer file, as Centreon, Saleor and
     * Socioboard do), and demoting the whole compose over it wrote the wrong
     * container topology. A datastore's `./data:/var/lib/mysql` is not reached
     * here: that service carries an `image:`, and the caller only asks about
     * services that `build:`.
     *
     * @param array<string, mixed> $service
     */
    private static function projectRootBindSource(array $service): ?string
    {
        $volumes = $service['volumes'] ?? null;
        if (!is_array($volumes)) {
            return null;
        }
        foreach ($volumes as $volume) {
            [$source, $target, $readOnly] = self::readBindVolume($volume);
            if ($source === null) {
                continue;
            }
            if (in_array($source, ['.', './', '${PWD}', '$PWD'], true)) {
                return $source;
            }
            if (!str_starts_with($source, './')) {
                continue;
            }
            if ($readOnly || self::mountsSingleFile($source, $target)) {
                continue;
            }

            return $source;
        }

        return null;
    }

    /**
     * A bind volume as [source, target, readOnly]; source is null for a named
     * volume or a non-bind mount.
     *
     * @param mixed $volume
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private static function readBindVolume(mixed $volume): array
    {
        if (is_string($volume)) {
            if (!str_contains($volume, ':')) {
                return [null, '', false];
            }
            $parts = explode(':', $volume);
            $mode = isset($parts[2]) ? trim($parts[2]) : '';
            $readOnly = in_array('ro', array_map('trim', explode(',', $mode)), true);

            return [
                str_replace('\\', '/', trim($parts[0])),
                str_replace('\\', '/', trim($parts[1] ?? '')),
                $readOnly,
            ];
        }
        if (is_array($volume)) {
            $type = strtolower((string) ($volume['type'] ?? 'bind'));
            if ($type !== '' && $type !== 'bind') {
                return [null, '', false];
            }
            $source = str_replace('\\', '/', trim((string) ($volume['source'] ?? '')));

            return [
                $source === '' ? null : $source,
                str_replace('\\', '/', trim((string) ($volume['target'] ?? ''))),
                ($volume['read_only'] ?? false) === true,
            ];
        }

        return [null, '', false];
    }

    /**
     * A bind of one file rather than a source tree: the source's last segment
     * or the target's carries a filename extension. One file is always an
     * injected artifact, never a mounted app directory.
     */
    private static function mountsSingleFile(string $source, string $target): bool
    {
        return self::hasFileExtension($source) || self::hasFileExtension($target);
    }

    private static function hasFileExtension(string $path): bool
    {
        $base = basename($path);

        return $base !== '' && preg_match('/\.[A-Za-z0-9]{1,8}$/', $base) === 1;
    }

    /**
     * Engine-written compose (welcome nginx, framework recipe, Dockerfile
     * wrap). Skipping it lets a zip of HTML or a known framework win on
     * rebuild instead of freezing strategy=compose.
     */
    public static function isGeneratedBootstrapCompose(string $composePath): bool
    {
        if (!is_file($composePath)) {
            return false;
        }
        // createFromTemplate used to write mode 600 owned by the app user.
        // PHP-FPM (www-data) then cannot read the generated-label and would
        // freeze strategy=compose over a zip that already has real sources.
        if (!is_readable($composePath)) {
            return true;
        }
        $raw = @file_get_contents($composePath);
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        if (str_contains($raw, GeneratedCompose::LABEL)) {
            return true;
        }
        if (str_contains($raw, DockerfileBuilder::FILENAME)) {
            return true;
        }

        return str_contains($raw, 'nginx:alpine')
            && str_contains($raw, '/usr/share/nginx/html')
            && !str_contains($raw, 'build:')
            && substr_count($raw, 'image:') === 1;
    }
}
