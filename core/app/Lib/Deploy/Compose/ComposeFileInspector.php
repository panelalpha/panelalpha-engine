<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Deploy\Sidecar\SidecarEngine;

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
     * `UID`/`GID` are included and matched case-insensitively because a
     * hand-written Dockerfile tends to say `ARG uid` / `useradd -u $uid`.
     */
    private const HOST_UID_VARS = 'WWWGROUP|WWWUSER|PUID|PGID|USER_ID|GROUP_ID|USERID|GROUPID|USER_UID|USER_GID'
        . '|HOST_UID|HOST_GID|HOSTUID|HOSTGID|UID|GID';

    /** @var list<string> */
    private const PROJECT_ROOT_SOURCES = ['.', './', '${PWD}', '$PWD'];

    /**
     * Datastores and storage emulators with no sidecar dialect: never the
     * application, which is all the sidecars-only test needs to know.
     */
    private const DIALECTLESS_DATASTORE_IMAGES = '#^(?:mcr\.microsoft\.com/(?:mssql/|azure-storage/azurite$|azure-sql-edge$)'
        . '|(?:[^/]+/)*cockroachdb/cockroach$|(?:[^/]+/)*gvenzl/oracle-|container-registry\.oracle\.com/database/'
        . '|(?:[^/]+/)*[^/]*oracle[^/]*-xe)#';

    private const STATIC_BOOTSTRAP_IMAGE = 'nginx:alpine';

    private const STATIC_BOOTSTRAP_DOCROOT = '/usr/share/nginx/html';

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
     * Compose files a repo ships as a template rather than as a live stack:
     * the author documents the full stack and expects the operator to copy it
     * and fill in secrets. They are read for their runtime services only; the
     * file itself is never run. Stack slices named by engine
     * (`docker-compose.mysql.yml`) count too.
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
     * Every service's build Dockerfile; a file under docker/ builds relative to docker/.
     *
     * @return list<array{relative: string, absolute: string}>
     */
    private static function composeBuildDockerfileRefs(string $composePath, string $projectDir): array
    {
        $raw = self::readNonEmpty($composePath);
        $parsed = $raw === null ? null : self::parseOrNull($raw);
        if (self::servicesOf($parsed) === null) {
            return [];
        }
        $nested = NestedCompose::relativeDir($composePath, $projectDir);
        if ($nested !== null) {
            $parsed = NestedCompose::rebase($parsed, $nested);
        }

        $refs = [];
        foreach ($parsed['services'] as $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            if (!is_string($build) && !is_array($build)) {
                continue;
            }
            $resolved = self::resolveComposeBuildDockerfile($projectDir, $build);
            if ($resolved !== null) {
                $refs[] = $resolved;
            }
        }

        return $refs;
    }

    /**
     * @param array<string, mixed>|string $build
     * @return array{relative: string, absolute: string}|null
     */
    private static function resolveComposeBuildDockerfile(string $projectDir, array|string $build): ?array
    {
        $paths = self::buildContextAndDockerfile($build);
        if ($paths === null || self::isUncheckableBuildPath(...$paths)) {
            return null;
        }
        [$context, $dockerfile] = $paths;
        if (str_starts_with($dockerfile, '/')) {
            return [
                'relative' => $dockerfile,
                'absolute' => $dockerfile,
            ];
        }

        $relative = self::dockerfileInContext($context, $dockerfile);
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return [
            'relative' => $relative,
            'absolute' => rtrim($projectDir, '/') . '/' . ltrim($relative, '/'),
        ];
    }

    /**
     * A `build:`'s context and Dockerfile with compose's defaults applied, or
     * null for an inline Dockerfile.
     *
     * @param array<string, mixed>|string $build
     * @return array{0: string, 1: string}|null
     */
    private static function buildContextAndDockerfile(array|string $build): ?array
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

        return [str_replace('\\', '/', trim($context)), str_replace('\\', '/', trim($dockerfile))];
    }

    /** Empty, a git/HTTP context, or a path climbing out with `..`: nothing on disk to check. */
    private static function isUncheckableBuildPath(string $context, string $dockerfile): bool
    {
        return $context === '' || $dockerfile === ''
            || preg_match('#^(https?://|git@|ssh://)#i', $context) === 1
            || str_contains($context, '..') || str_contains($dockerfile, '..');
    }

    private static function dockerfileInContext(string $context, string $dockerfile): string
    {
        $contextRel = self::stripDotSlash($context);
        $fileRel = self::stripDotSlash($dockerfile);
        if ($contextRel === '' || $contextRel === '.') {
            return $fileRel;
        }

        return rtrim($contextRel, '/') . '/' . ltrim($fileRel, '/');
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
        $raw = self::readNonEmpty($composePath);

        return $raw !== null && self::isLocalDevComposeYaml($raw);
    }

    public static function isLocalDevComposeYaml(string $raw): bool
    {
        return self::localDevComposeReasonYaml($raw) !== null;
    }

    /**
     * Why a file reads as a workstation dev compose, or null when it does not.
     * The boolean check and the deploy log share this, so a demotion can name
     * the service and mount that triggered it.
     */
    public static function localDevComposeReason(string $composePath): ?string
    {
        $raw = self::readNonEmpty($composePath);

        return $raw === null ? null : self::localDevComposeReasonYaml($raw);
    }

    public static function localDevComposeReasonYaml(string $raw): ?string
    {
        foreach (self::servicesOf(self::parseOrNull($raw)) ?? [] as $name => $service) {
            $reason = is_array($service) ? self::workstationServiceReason((string) $name, $service) : null;
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function workstationServiceReason(string $name, array $service): ?string
    {
        if (self::serviceHasUndefaultedHostUidBuildArg($service)) {
            return 'service `' . $name . '` maps a host UID/GID build arg';
        }
        if (isset($service['build']) && ($source = self::projectRootBindSource($service)) !== null) {
            $where = self::isProjectRootSource($source)
                ? 'the project root'
                : '`' . $source . '`';

            return 'service `' . $name . '` mounts ' . $where;
        }

        return null;
    }

    /**
     * A Compose v1 file: services at the top level, no `services:` mapping.
     * Compose v2 refuses the whole file (`additional properties 'rapidbay'
     * not allowed`), so running it can only fail.
     */
    public static function isLegacyV1Compose(string $composePath): bool
    {
        $raw = self::readNonEmpty($composePath);

        return $raw !== null && self::isLegacyV1ComposeYaml($raw);
    }

    /** `include:` alone is a valid v2 file with no services of its own. */
    public static function isLegacyV1ComposeYaml(string $raw): bool
    {
        $parsed = self::parseOrNull($raw);
        if (!is_array($parsed) || isset($parsed['services']) || isset($parsed['include'])) {
            return false;
        }
        foreach ($parsed as $value) {
            if (is_array($value) && (isset($value['image']) || isset($value['build']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why `docker compose up` starts nothing from this file as written: every
     * service sits behind a profile nobody activates, or one has neither image
     * nor build and Compose rejects the project. Null when it can start something.
     *
     * @param list<string> $activeProfiles COMPOSE_PROFILES the repository sets
     */
    public static function startsNothingReasonYaml(string $raw, array $activeProfiles = []): ?string
    {
        $parsed = self::parseOrNull($raw);
        $services = self::servicesOf($parsed);
        if ($services === null || isset($parsed['include'])) {
            return null;
        }

        $startable = false;
        foreach ($services as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            if (!isset($service['image']) && !isset($service['build']) && !isset($service['extends'])) {
                return "service `{$name}` has neither an image nor a build";
            }
            $profiles = is_array($service['profiles'] ?? null) ? $service['profiles'] : [];
            if ($profiles === [] || array_intersect($profiles, $activeProfiles) !== []) {
                $startable = true;
            }
        }

        return $startable || $services === [] ? null : 'every service is behind a profile';
    }

    /**
     * COMPOSE_PROFILES as the repository's committed `.env` sets it.
     *
     * @return list<string>
     */
    public static function profilesFromEnvFile(?string $env): array
    {
        if ($env === null || preg_match('/^\s*COMPOSE_PROFILES\s*=\s*["\']?([^"\'\n#]*)/m', $env, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }

    /**
     * Compose file whose every service is a known datastore (postgres, redis, …).
     * Treat as sidecar inventory for framework/railpack deploys — not STRATEGY_COMPOSE.
     */
    public static function isSidecarsOnlyCompose(string $composePath): bool
    {
        $raw = self::readNonEmpty($composePath);

        return $raw !== null && self::isSidecarsOnlyComposeYaml($raw);
    }

    public static function isSidecarsOnlyComposeYaml(string $raw): bool
    {
        $seen = 0;
        foreach (self::servicesOf(self::parseOrNull($raw)) ?? [] as $name => $service) {
            if (!is_array($service)) {
                continue;
            }
            $seen++;
            if (isset($service['build']) || self::isWorkstationAppService($service)
                || !self::isDatastoreOrConsole((string) $name, $service)
            ) {
                return false;
            }
        }

        return $seen > 0;
    }

    /**
     * A datastore or a console that comes with one: a file of only these is a
     * development environment with no application in it. A published port's
     * number alone does not make one (an app may serve on Elasticsearch's 9200).
     *
     * @param array<string, mixed> $service
     */
    private static function isDatastoreOrConsole(string $name, array $service): bool
    {
        $identity = isset($service['ports']) ? array_diff_key($service, ['ports' => true, 'expose' => true]) : $service;

        return SidecarEngine::isKnownDatastore($name, $identity)
            || DevServices::isDevSidecar($name, $service)
            || self::isDialectlessDatastore($service);
    }

    /** @param array<string, mixed> $service */
    private static function isDialectlessDatastore(array $service): bool
    {
        $image = $service['image'] ?? null;
        if (!is_string($image) || trim($image) === '') {
            return false;
        }
        $repository = preg_replace('#:[^/]*$#', '', explode('@', strtolower(trim($image)), 2)[0]);

        return preg_match(self::DIALECTLESS_DATASTORE_IMAGES, (string) $repository) === 1;
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
     * Dockerfile that maps a host UID/GID with no numeric ARG or ENV default.
     * Nested copies of local-dev runtimes (docker/8.x, docker/Dockerfile)
     * must not win over PHP/JS/Railpack recipes. Heredoc bodies are ignored:
     * an entrypoint written as one reads $PUID at run time, not from a build ARG.
     */
    public static function isHostUidMappedDockerfile(string $path): bool
    {
        $raw = self::readNonEmpty($path);
        if ($raw === null) {
            return false;
        }
        $raw = DockerfileFinder::withoutHeredocBodies($raw);
        if (preg_match('/\b(groupadd|useradd|addgroup|adduser)\b/', $raw) !== 1) {
            return false;
        }
        if (preg_match('/\$(?:\{)?(' . self::HOST_UID_VARS . ')\b/i', $raw, $match) !== 1) {
            return false;
        }

        return !self::hasNumericDefault($raw, preg_quote($match[1], '/'));
    }

    /** An `ARG` default, or an `ENV` value (`ENV PUID=1001`), which sets it as surely. */
    private static function hasNumericDefault(string $dockerfile, string $quotedVar): bool
    {
        return preg_match('/^\s*ARG\s+' . $quotedVar . '\s*=\s*\d+/mi', $dockerfile) === 1
            || preg_match('/^\s*ENV\s+(?:(?:\S+=\S+\s+)*' . $quotedVar . '\s*=\s*["\']?\d+|' . $quotedVar . '\s+["\']?\d+)/mi', $dockerfile) === 1;
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
     * The service mounts the whole checkout (`.`/`./`/`${PWD}`), with or
     * without `build:`: it runs the repository's own code from a laptop's
     * working tree.
     *
     * @param array<string, mixed> $service
     */
    public static function mountsWholeProjectRoot(array $service): bool
    {
        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            if (self::isProjectRootSource(self::readBindVolume($volume)[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bind source that is the project root, as written or through a default
     * (`${APP_SRC:-.}`). Both forms: resolving a bare `${PWD}` empties it.
     */
    private static function isProjectRootSource(?string $source): bool
    {
        return $source !== null && (
            in_array($source, self::PROJECT_ROOT_SOURCES, true)
            || in_array(EnvVarDefault::resolve($source), self::PROJECT_ROOT_SOURCES, true)
        );
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function serviceBindsProjectRoot(array $service): bool
    {
        return self::projectRootBindSource($service) !== null;
    }

    /**
     * The bind source that marks a service a workstation app service, or null:
     * the project root, which a laptop mounts over the built image for live
     * reload, or a {@see isSourceTreeBind() source-tree subdirectory}.
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
            if ($source !== null && (self::isProjectRootSource($source) || self::isSourceTreeBind($source, $target, $readOnly))) {
                return $source;
            }
        }

        return null;
    }

    /**
     * A read-write `./subdir` holding source (a whole shop under `./upload`).
     * Not a read-only bind, a single injected file or a log directory: those
     * are a recipe injecting an artifact, not a workstation.
     */
    private static function isSourceTreeBind(string $source, string $target, bool $readOnly): bool
    {
        return str_starts_with($source, './')
            && !$readOnly
            && !self::mountsSingleFile($source, $target)
            && !self::mountsLogDirectory($source, $target);
    }

    /**
     * `./nginx/log:/var/log/nginx`, `./logs:/app/logs`: a directory the
     * service only writes its logs to. It holds no source and the stack runs
     * with it empty, so it says nothing about a workstation.
     */
    private static function mountsLogDirectory(string $source, string $target): bool
    {
        $target = rtrim($target, '/');
        if ($target === '/var/log' || str_starts_with($target, '/var/log/')) {
            return true;
        }
        foreach ([$source, $target] as $path) {
            if (preg_match('/^logs?$/i', basename(rtrim($path, '/'))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A bind volume as [source, target, readOnly]; source is null for a named
     * volume or a non-bind mount.
     *
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private static function readBindVolume(mixed $volume): array
    {
        if (is_string($volume)) {
            return self::readShortBindVolume($volume);
        }
        if (is_array($volume)) {
            return self::readLongBindVolume($volume);
        }

        return [null, '', false];
    }

    /**
     * Split brace-aware: `${APP_SRC:-.}:/var/www/html` is two fields, not three.
     *
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private static function readShortBindVolume(string $volume): array
    {
        if (!str_contains($volume, ':')) {
            return [null, '', false];
        }
        $parts = PortMapping::splitFields($volume);
        $mode = isset($parts[2]) ? trim($parts[2]) : '';
        $readOnly = in_array('ro', array_map('trim', explode(',', $mode)), true);

        return [
            str_replace('\\', '/', trim($parts[0])),
            str_replace('\\', '/', trim($parts[1] ?? '')),
            $readOnly,
        ];
    }

    /**
     * @param array<mixed> $volume
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private static function readLongBindVolume(array $volume): array
    {
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
     * rebuild instead of freezing strategy=compose. An unreadable file counts
     * as generated: the engine's own may be mode 600 to the app user, which
     * PHP-FPM (www-data) cannot read.
     */
    public static function isGeneratedBootstrapCompose(string $composePath): bool
    {
        if (!is_file($composePath)) {
            return false;
        }
        if (!is_readable($composePath)) {
            return true;
        }
        $raw = self::readNonEmpty($composePath);
        if ($raw === null) {
            return false;
        }

        return self::hasEngineService(ComposeYaml::parse($raw)) || self::isUnlabelledStaticBootstrapYaml($raw);
    }

    /**
     * A service carrying the engine's label or building its Dockerfile. A
     * customer file that only mentions either in a comment is still theirs.
     */
    private static function hasEngineService(mixed $parsed): bool
    {
        foreach (is_array($parsed) ? ($parsed['services'] ?? []) : [] as $service) {
            if (!is_array($service)) {
                continue;
            }
            if (array_key_exists(GeneratedCompose::LABEL, (array) ($service['labels'] ?? []))
                || ($service['build']['dockerfile'] ?? null) === DockerfileBuilder::FILENAME
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The static bootstrap as the engine wrote it before it carried the label:
     * one `nginx:alpine` service, no build, serving the project root itself.
     * Only the root: a client stack serving `./html` or `./dist` with the same
     * image is the client's own.
     */
    private static function isUnlabelledStaticBootstrapYaml(string $raw): bool
    {
        $parsed = self::parseOrNull($raw);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;
        if (!is_array($services) || count($services) !== 1) {
            return false;
        }
        $service = reset($services);
        if (!is_array($service) || isset($service['build']) || ($service['image'] ?? null) !== self::STATIC_BOOTSTRAP_IMAGE) {
            return false;
        }

        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            if (is_string($volume) && self::servesProjectRootAsDocroot($volume)) {
                return true;
            }
        }

        return false;
    }

    private static function servesProjectRootAsDocroot(string $volume): bool
    {
        $parts = explode(':', $volume);

        return count($parts) >= 2
            && in_array(trim($parts[0]), ['.', './'], true)
            && rtrim(trim($parts[1]), '/') === self::STATIC_BOOTSTRAP_DOCROOT;
    }

    /** The file's contents, or null when it is missing, unreadable or empty. */
    private static function readNonEmpty(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function parseOrNull(string $raw): ?array
    {
        try {
            return ComposeYaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed>|null $parsed
     * @return array<mixed>|null
     */
    private static function servicesOf(?array $parsed): ?array
    {
        return is_array($parsed['services'] ?? null) ? $parsed['services'] : null;
    }
}
