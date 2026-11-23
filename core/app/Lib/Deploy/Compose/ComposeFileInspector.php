<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * What kind of compose file this is: a live stack, a workstation bind-mount setup, a template nothing
 * loads, or engine-written bootstrap. Read by {@see DetectProjectStrategy} and the sidecar readers.
 */
class ComposeFileInspector
{
    /**
     * Host-user mapping vars, passed from the laptop and empty in hosting, where `groupadd -g` exits 3.
     * `UID`/`GID` are matched case-insensitively for a hand-written `ARG uid` / `useradd -u $uid`.
     */
    private const HOST_UID_VARS = 'WWWGROUP|WWWUSER|PUID|PGID|USER_ID|GROUP_ID|USERID|GROUPID|USER_UID|USER_GID'
        .'|HOST_UID|HOST_GID|HOSTUID|HOSTGID|UID|GID';

    /** @var list<string> */
    private const PROJECT_ROOT_SOURCES = ['.', './', '${PWD}', '$PWD'];

    /**
     * Datastores and storage emulators with no sidecar dialect: never the
     * application, which is all the sidecars-only test needs to know.
     */
    private const DIALECTLESS_DATASTORE_IMAGES = '#^(?:mcr\.microsoft\.com/(?:mssql/|azure-storage/azurite$|azure-sql-edge$)'
        .'|(?:[^/]+/)*cockroachdb/cockroach$|(?:[^/]+/)*gvenzl/oracle-|container-registry\.oracle\.com/database/'
        .'|(?:[^/]+/)*[^/]*oracle[^/]*-xe)#';

    /** The one name the engine wrote its static bootstrap under. */
    private const STATIC_BOOTSTRAP_FILE = 'docker-compose.yml';

    private const STATIC_BOOTSTRAP_IMAGE = 'nginx:alpine';

    private const STATIC_BOOTSTRAP_DOCROOT = '/usr/share/nginx/html';

    /** @var list<string> the only keys the static bootstrap gave its service */
    private const STATIC_BOOTSTRAP_SERVICE_KEYS = ['image', 'ports', 'volumes'];

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
     * Compose files a repo ships as a template for the operator to copy, stack slices such as
     * `docker-compose.mysql.yml` included: read for their runtime services only, never run.
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
            $path = $root.'/'.$candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Compose-spec Dockerfile paths missing on disk. `dockerfile:` is relative to `build.context`:
     * `context: ./console` + `dockerfile: Dockerfile` → `console/Dockerfile`.
     *
     * @return list<string> project-relative paths (for error messages)
     */
    public static function missingComposeDockerfileRefs(string $composePath, string $projectDir): array
    {
        $missing = [];
        foreach (self::composeBuildDockerfileRefs($composePath, $projectDir) as $ref) {
            if (! is_file($ref['absolute'])) {
                $missing[] = $ref['relative'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Absolute Dockerfile a compose `build:` would use, or null when there
     * is no on-disk file to check (inline Dockerfile, git/HTTP context).
     *
     * @param  array<string, mixed>|string  $build
     */
    public static function composeBuildDockerfileAbsolute(string $projectDir, array|string $build): ?string
    {
        $resolved = ComposeBuildDockerfile::resolve($projectDir, $build);

        return $resolved['absolute'] ?? null;
    }

    /**
     * True when the compose build context is the project root (`.`).
     *
     * @param  array<string, mixed>|string  $build
     */
    public static function composeBuildContextIsProjectRoot(array|string $build): bool
    {
        if (is_string($build)) {
            $context = $build;
        } else {
            $context = $build['context'] ?? '.';
            if (! is_string($context)) {
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
        $parsed = self::rebasedComposeWithServices($composePath, $projectDir);
        if ($parsed === null) {
            return [];
        }

        $refs = [];
        foreach ($parsed['services'] as $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            if (! is_string($build) && ! is_array($build)) {
                continue;
            }
            $resolved = ComposeBuildDockerfile::resolve($projectDir, $build);
            if ($resolved !== null) {
                $refs[] = $resolved;
            }
        }

        return $refs;
    }

    /**
     * The parsed file with its paths rebased onto the project root, or null when it has no services.
     *
     * @return array<mixed>|null
     */
    private static function rebasedComposeWithServices(string $composePath, string $projectDir): ?array
    {
        $raw = self::readNonEmpty($composePath);
        $parsed = $raw === null ? null : self::parseOrNull($raw);
        if (self::servicesOf($parsed) === null) {
            return null;
        }
        $nested = NestedCompose::relativeDir($composePath, $projectDir);
        if ($nested !== null) {
            $parsed = NestedCompose::rebase($parsed, $nested);
        }

        return $parsed;
    }

    /**
     * Local-dev compose: a bind-mount of the repo plus a build, or build args interpolating a host
     * UID/GID with no default, as Sail, Symfony Docker, Vite and similar workstation stacks do.
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
     * Why a file reads as a workstation dev compose, or null. Shared with the deploy log, so a demotion
     * can name the service and mount that triggered it.
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
     * @param  array<string, mixed>  $service
     */
    private static function workstationServiceReason(string $name, array $service): ?string
    {
        if (self::serviceHasUndefaultedHostUidBuildArg($service)) {
            return 'service `'.$name.'` maps a host UID/GID build arg';
        }
        if (isset($service['build']) && ($source = self::projectRootBindSource($service)) !== null) {
            $where = self::isProjectRootSource($source)
                ? 'the project root'
                : '`'.$source.'`';

            return 'service `'.$name.'` mounts '.$where;
        }

        return null;
    }

    /**
     * A Compose v1 file: services at the top level, no `services:` mapping. Compose v2 refuses the
     * whole file (`additional properties 'rapidbay' not allowed`), so running it can only fail.
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
        if (! is_array($parsed) || isset($parsed['services']) || isset($parsed['include'])) {
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
     * Why `docker compose up` starts nothing from this file: it defines no service, every service is behind an
     * inactive profile, or one has neither image nor build and Compose rejects the project. Null otherwise.
     *
     * @param  list<string>  $activeProfiles  COMPOSE_PROFILES the repository sets
     */
    public static function startsNothingReasonYaml(string $raw, array $activeProfiles = []): ?string
    {
        $parsed = self::parseOrNull($raw);
        if ($parsed === null || isset($parsed['include'])) {
            return null;
        }
        $services = self::servicesOf($parsed);
        if ($services === null || $services === []) {
            return 'defines no services';
        }

        $startable = false;
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            if (! isset($service['image']) && ! isset($service['build']) && ! isset($service['extends'])) {
                return "service `{$name}` has neither an image nor a build";
            }
            $profiles = is_array($service['profiles'] ?? null) ? $service['profiles'] : [];
            if ($profiles === [] || array_intersect($profiles, $activeProfiles) !== []) {
                $startable = true;
            }
        }

        return $startable ? null : 'every service is behind a profile';
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
            if (! is_array($service)) {
                continue;
            }
            $seen++;
            if (isset($service['build']) || self::isWorkstationAppService($service)
                || ! self::isDatastoreOrConsole((string) $name, $service)
            ) {
                return false;
            }
        }

        return $seen > 0;
    }

    /**
     * A datastore or a console that comes with one: a file of only these has no application in it.
     * A published port's number alone does not make one (an app may serve on Elasticsearch's 9200).
     *
     * @param  array<string, mixed>  $service
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
        if (! is_string($image) || trim($image) === '') {
            return false;
        }
        $repository = preg_replace('#:[^/]*$#', '', explode('@', strtolower(trim($image)), 2)[0]);

        return preg_match(self::DIALECTLESS_DATASTORE_IMAGES, (string) $repository) === 1;
    }

    /**
     * The workstation app service (bind-mount of the repo and/or host UID
     * build args). Image-only sidecars (mysql, redis, typesense) are not this.
     *
     * @param  array<string, mixed>  $service
     */
    public static function isWorkstationAppService(array $service): bool
    {
        if (self::serviceHasUndefaultedHostUidBuildArg($service)) {
            return true;
        }

        return isset($service['build']) && self::serviceBindsProjectRoot($service);
    }

    /**
     * A Dockerfile mapping a host UID/GID with no numeric ARG or ENV default, so local-dev runtimes do
     * not win over recipes. Heredoc bodies are ignored: such an entrypoint reads $PUID at run time.
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
        if (preg_match('/\$(?:\{)?('.self::HOST_UID_VARS.')\b/i', $raw, $match) !== 1) {
            return false;
        }

        return ! self::hasNumericDefault($raw, preg_quote($match[1], '/'));
    }

    /** An `ARG` default, or an `ENV` value (`ENV PUID=1001`), which sets it as surely. */
    private static function hasNumericDefault(string $dockerfile, string $quotedVar): bool
    {
        return preg_match('/^\s*ARG\s+'.$quotedVar.'\s*=\s*\d+/mi', $dockerfile) === 1
            || preg_match('/^\s*ENV\s+(?:(?:\S+=\S+\s+)*'.$quotedVar.'\s*=\s*["\']?\d+|'.$quotedVar.'\s+["\']?\d+)/mi', $dockerfile) === 1;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function serviceHasUndefaultedHostUidBuildArg(array $service): bool
    {
        $build = $service['build'] ?? null;
        if (! is_array($build) || ! isset($build['args'])) {
            return false;
        }
        $args = $build['args'];
        if (! is_array($args)) {
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
        return preg_match('/\$\{(?:'.self::HOST_UID_VARS.')\}/', $value) === 1;
    }

    /**
     * The service mounts the whole checkout (`.`/`./`/`${PWD}`), with or without `build:`: it runs
     * the repository's own code from a laptop's working tree.
     *
     * @param  array<string, mixed>  $service
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
     * @param  array<string, mixed>  $service
     */
    private static function serviceBindsProjectRoot(array $service): bool
    {
        return self::projectRootBindSource($service) !== null;
    }

    /**
     * The bind source that marks a workstation app service, or null: the project root a laptop mounts
     * over the image for live reload, or a {@see isSourceTreeBind() source-tree subdirectory}.
     *
     * @param  array<string, mixed>  $service
     */
    private static function projectRootBindSource(array $service): ?string
    {
        $volumes = $service['volumes'] ?? null;
        if (! is_array($volumes)) {
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
     * A read-write `./subdir` holding source (a whole shop under `./upload`). A read-only bind, one
     * file or a log directory is a recipe injecting an artifact, not a workstation.
     */
    private static function isSourceTreeBind(string $source, string $target, bool $readOnly): bool
    {
        return str_starts_with($source, './')
            && ! $readOnly
            && ! self::mountsSingleFile($source, $target)
            && ! self::mountsLogDirectory($source, $target);
    }

    /**
     * `./nginx/log:/var/log/nginx`, `./logs:/app/logs`: a directory the service only writes logs to,
     * which holds no source and says nothing about a workstation.
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
        if (! str_contains($volume, ':')) {
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
     * @param  array<mixed>  $volume
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
     * A bind of one file, whose source or target ends in a filename extension: always an injected
     * artifact, never a mounted app directory.
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
     * Why detection passes over the compose file at $composePath, worded for the deploy log: it cannot be read, it
     * reads as the engine's own, it is a workstation dev compose, it has the v1 layout, or it starts nothing.
     * Null when none applies.
     */
    public static function ignoredReason(string $composePath): ?string
    {
        if (is_file($composePath) && ! is_readable($composePath)) {
            return 'cannot be read by the engine';
        }
        if (self::isGeneratedBootstrapCompose($composePath)) {
            return "reads as one the engine generated, not the project's own";
        }
        $workstation = self::localDevComposeReason($composePath);
        if ($workstation !== null) {
            return "looks like a workstation dev compose ({$workstation})";
        }
        if (self::isLegacyV1Compose($composePath)) {
            return 'uses the v1 layout (no `services:`), which Docker Compose v2 refuses';
        }
        $startsNothing = self::startsNothingReason($composePath);
        if ($startsNothing !== null) {
            return "starts nothing as written ({$startsNothing})";
        }

        return null;
    }

    /** {@see startsNothingReasonYaml()} for a file on disk, with the profiles its directory's `.env` activates. */
    private static function startsNothingReason(string $composePath): ?string
    {
        $raw = self::readNonEmpty($composePath);
        $profiles = self::profilesFromEnvFile(self::readNonEmpty(dirname($composePath).'/.env'));

        return $raw === null ? null : self::startsNothingReasonYaml($raw, $profiles);
    }

    /**
     * Engine-written compose, skipped so a known framework wins on rebuild instead of freezing
     * strategy=compose. An unreadable one counts: the engine's own may be mode 600 to the app user.
     */
    public static function isGeneratedBootstrapCompose(string $composePath): bool
    {
        if (! is_file($composePath)) {
            return false;
        }
        if (! is_readable($composePath)) {
            return true;
        }
        $raw = self::readNonEmpty($composePath);
        if ($raw === null) {
            return false;
        }

        return self::hasEngineService(ComposeYaml::parse($raw))
            || (basename($composePath) === self::STATIC_BOOTSTRAP_FILE && self::isUnlabelledStaticBootstrapYaml($raw));
    }

    /**
     * A service carrying the engine's label or building its Dockerfile. A
     * customer file that only mentions either in a comment is still theirs.
     */
    private static function hasEngineService(mixed $parsed): bool
    {
        foreach (is_array($parsed) ? ($parsed['services'] ?? []) : [] as $service) {
            if (! is_array($service)) {
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
     * The static bootstrap from before the label, as the engine wrote it: one `app` service on `nginx:alpine` with the
     * project root mounted on the docroot, ports or none. Any other key, service or volume is the client's own.
     */
    private static function isUnlabelledStaticBootstrapYaml(string $raw): bool
    {
        $parsed = self::parseOrNull($raw);
        if ($parsed === null || array_keys($parsed) !== ['services']) {
            return false;
        }
        $services = self::servicesOf($parsed);
        if ($services === null || array_keys($services) !== [GeneratedCompose::APP_SERVICE]) {
            return false;
        }
        $service = $services[GeneratedCompose::APP_SERVICE];
        if (! is_array($service)
            || array_diff(array_keys($service), self::STATIC_BOOTSTRAP_SERVICE_KEYS) !== []
            || ($service['image'] ?? null) !== self::STATIC_BOOTSTRAP_IMAGE
        ) {
            return false;
        }

        return self::servesOnlyTheProjectRoot($service['volumes'] ?? null);
    }

    /** At least one volume, each the project root bound onto the docroot. */
    private static function servesOnlyTheProjectRoot(mixed $volumes): bool
    {
        if (! is_array($volumes) || $volumes === []) {
            return false;
        }
        foreach ($volumes as $volume) {
            if (! is_string($volume) || ! self::servesProjectRootAsDocroot($volume)) {
                return false;
            }
        }

        return true;
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
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = AccountHomeRead::contents($path);

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
     * @param  array<mixed>|null  $parsed
     * @return array<mixed>|null
     */
    private static function servicesOf(?array $parsed): ?array
    {
        return is_array($parsed['services'] ?? null) ? $parsed['services'] : null;
    }
}
