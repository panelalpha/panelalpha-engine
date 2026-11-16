<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Port\InternalPorts;
use App\Lib\Deploy\Port\PortMapping;
use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarDialects;
use App\Lib\Deploy\Sidecar\SidecarEngine;
use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Makes one service from a customer's compose file safe to run inside an account: removes what would
 * escape the isolation boundary or reach the inner Docker daemon, and caps what it may consume.
 */
final class ServiceHardener
{
    /**
     * Escapes and cgroup/runtime/OOM opt-outs, denied so new keys survive; `privileged`: its caps go singly.
     * An `extends` here skipped the file reader and merges unhardened; `storage_opt` fails off xfs+pquota.
     *
     * @see MountSourcePolicy bind sources, an allowlist
     * @see withSafeCapabilities() `cap_add`, an allowlist
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'extends',
        'privileged',
        'pid',
        'ipc',
        'uts',
        'devices',
        'security_opt',
        'userns_mode',
        'cgroup_parent',
        'cgroup',
        'group_add',
        'device_cgroup_rules',
        'cgroupns_mode',
        'runtime',
        'oom_kill_disable',
        'oom_score_adj',
        'storage_opt',
    ];

    /**
     * Docker's defaults, which `cap_add` may re-add without exceeding a default container's reach, and
     * NET_ADMIN, safe in the service's own netns and needed by binaries carrying it as a file capability.
     *
     * @var list<string>
     */
    private const DEFAULT_CAPABILITIES = [
        'CHOWN', 'DAC_OVERRIDE', 'FSETID', 'FOWNER', 'MKNOD', 'NET_RAW', 'SETGID',
        'SETUID', 'SETFCAP', 'SETPCAP', 'NET_BIND_SERVICE', 'SYS_CHROOT', 'KILL', 'AUDIT_WRITE',
        'NET_ADMIN',
    ];

    /**
     * Sysctls scoped to the service's own network namespace; every other one
     * is removed ({@see withNamespacedSysctls()}).
     *
     * @var list<string>
     */
    private const NAMESPACED_SYSCTLS = ['net.ipv4.ping_group_range', 'net.ipv4.ip_unprivileged_port_start'];

    /**
     * Docker's default lets any group open ICMP sockets, but a daemon in the account's user namespace
     * skips it, so non-root ping fails. The upper end is the account's own gid range.
     */
    private const PING_GROUP_RANGE = '0 65535';

    /**
     * The account's hard RLIMIT_NOFILE (Docker's default, measured in an
     * account). runc refuses more: "error setting rlimit type 7".
     */
    private const MAX_NOFILE = 524288;

    private const NODE_COMMAND_PATTERN = '/\b(node|nodejs|npm|npx|pnpm|yarn|bun)\b/';

    /** Names of languages and servers — vocabulary, not a product catalogue. */
    private const NON_NODE_IMAGE_PATTERN = '#\b(nginx|httpd|caddy|traefik|haproxy|varnish|envoy'
        .'|php|wordpress|ruby|python|golang|openjdk|eclipse-temurin|rust|dotnet|erlang)\b#';

    private const DATABASE_CPUS = '0.50';

    private const APPLICATION_CPUS = '0.75';

    /** Grace a CPU-capped service's own healthcheck gets when its author gave none. */
    private const CAPPED_START_PERIOD = '60s';

    /**
     * Enough for a supervisor running several daemons that fork per check,
     * still a cap on a fork bomb. A per-service pids_limit overrides it.
     */
    private const PIDS_LIMIT = 1024;

    /** At most 30 MB of log per container; the account's daemon defaults to the same. */
    public const LOG_OPTIONS = ['max-size' => '10m', 'max-file' => '3'];

    /**
     * Thread pools sized from the visible core count (OpenMP, MKL, OpenBLAS): a CPU quota does not hide
     * the host's cores, so without these a pool runs a thread per host core under a fraction of one.
     *
     * @var list<string>
     */
    private const THREAD_POOL_VARIABLES = ['OMP_NUM_THREADS', 'MKL_NUM_THREADS', 'OPENBLAS_NUM_THREADS'];

    private const LEGACY_POSTGRES_DATA = '/var/lib/postgresql/data';

    /** The tag in `name:tag@digest`, which only fails the reference checks. */
    private const TAG_BEFORE_DIGEST = '#:[\w][\w.-]*(?=@sha256:)#';

    /** A short-syntax port's `[::]:` host prefix. */
    private const IPV6_WILDCARD = '/^\s*\[\s*::\s*\]:/';

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env  what compose may interpolate with ({@see ComposeInterpolation})
     * @param  (callable(string): list<string>)|null  $imageEnvironment  an image's `Config.Env` ({@see withNodeHeapCap()})
     * @return array<string, mixed>
     */
    public static function harden(
        string $name,
        array $service,
        ?int $accountMemoryMb = null,
        bool $keepLoopbackPorts = false,
        array $env = [],
        ?string $accountUser = null,
        ?string $projectDir = null,
        ?callable $imageEnvironment = null
    ): array {
        $service = self::withHostNetworkPortPublished($service);
        $service = self::withoutEscapes($service, $env, $accountUser, $projectDir);
        $service = self::withRestartPolicy($service);
        $service = self::withLogRotation($service);
        $service = self::withoutDeployResources($service);
        $service = self::withMemoryLimit($name, $service, $accountMemoryMb, $env);
        $service = self::withNodeHeapCap($name, $service, $accountMemoryMb, $imageEnvironment, $env);
        $service = self::withReachablePublishedPorts($service, $keepLoopbackPorts);
        $service = self::withLegacyPostgresDataDir($service);
        $service = self::withPingGroupRange($service);

        $service = self::withProcessLimits($name, $service);

        return self::isDatabase($name, $service) ? $service : self::withThreadPoolsSizedToCpus($service);
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withThreadPoolsSizedToCpus(array $service): array
    {
        $cpus = EnvVarDefault::resolve(trim((string) ($service['cpus'] ?? $service['cpu_count'] ?? '')));
        if (! is_numeric($cpus) || (float) $cpus <= 0) {
            return $service;
        }

        $threads = (string) max(1, (int) ceil((float) $cpus));
        $defaults = [];
        foreach (self::THREAD_POOL_VARIABLES as $variable) {
            if (! ServiceEnvironment::hasKey($service['environment'] ?? null, $variable)) {
                $defaults[$variable] = $threads;
            }
        }
        if ($defaults !== []) {
            $service['environment'] = ServiceEnvironment::withDefaults($service['environment'] ?? [], $defaults);
        }

        return $service;
    }

    /**
     * `network_mode: host` is removed by {@see withoutEscapes()}, leaving a service that relied on it
     * publishing nothing, so the port its `PORT` env names is published instead.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withHostNetworkPortPublished(array $service): array
    {
        if (($service['network_mode'] ?? null) !== 'host' || ! empty($service['ports']) || ! empty($service['expose'])) {
            return $service;
        }
        $port = self::environmentValue($service['environment'] ?? null, 'PORT');
        if ($port === null || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return $service;
        }
        $service['ports'] = [$port.':'.$port];

        return $service;
    }

    /**
     * One variable from a service's `environment:`, list or map form.
     */
    private static function environmentValue(mixed $environment, string $name): ?string
    {
        if (! is_array($environment)) {
            return null;
        }
        foreach ($environment as $key => $value) {
            if (is_int($key) && is_string($value) && str_starts_with($value, $name.'=')) {
                return trim(substr($value, strlen($name) + 1), " \"'");
            }
            if ($key === $name && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * postgres 18+ refuses to start when /var/lib/postgresql/data, where files for 17 mount, is a mount
     * point; naming it as PGDATA skips that check, and on 17 and earlier it is the default anyway.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withLegacyPostgresDataDir(array $service): array
    {
        $family = ComposeService::familyOf((string) ($service['image'] ?? ''));
        if (SidecarDialects::canonical($family) !== 'postgres'
            || ServiceEnvironment::hasKey($service['environment'] ?? null, 'PGDATA')
        ) {
            return $service;
        }

        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            $target = is_array($volume) ? (string) ($volume['target'] ?? '') : self::shortMountTarget((string) $volume);
            if (rtrim(trim($target), '/') === self::LEGACY_POSTGRES_DATA) {
                $service['environment'] = ServiceEnvironment::withDefaults(
                    $service['environment'] ?? [],
                    ['PGDATA' => self::LEGACY_POSTGRES_DATA]
                );

                return $service;
            }
        }

        return $service;
    }

    /** The container side of a short mount: its second field, or the only one of an anonymous volume. */
    private static function shortMountTarget(string $volume): string
    {
        $fields = self::splitFields(EnvVarDefault::resolve(trim($volume)));

        return $fields[1] ?? $fields[0];
    }

    /**
     * A loopback host is dropped, as the account container is the boundary: PortMapping would discard the
     * binding, leaving detection a dead default. With $keepLoopback (another web port published) it stays.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withReachablePublishedPorts(array $service, bool $keepLoopback = false): array
    {
        $ports = $service['ports'] ?? null;
        if (! is_array($ports)) {
            return $service;
        }

        $publishedOutsideIpv6 = self::hostPortsPublishedOutsideIpv6Wildcard($ports);
        foreach ($ports as $index => $port) {
            $port = self::withIpv6WildcardOnEveryAddress($port, $publishedOutsideIpv6);
            $ports[$index] = self::withReachableHost($port, $keepLoopback);
        }

        $service['ports'] = $ports;

        return $service;
    }

    /**
     * @param  array<mixed>  $ports
     * @return array<int, true>
     */
    private static function hostPortsPublishedOutsideIpv6Wildcard(array $ports): array
    {
        $published = [];
        foreach ($ports as $port) {
            if (! self::isIpv6Wildcard($port) && ($hostPort = self::anyHostPort($port)) !== null) {
                $published[$hostPort] = true;
            }
        }

        return $published;
    }

    /**
     * Docker publishes `[::]` on IPv6 only while the proxy and health check use IPv4, so such a port
     * is published on every address unless another entry already publishes it there.
     *
     * @param  array<int, true>  $publishedOutsideIpv6
     */
    private static function withIpv6WildcardOnEveryAddress(mixed $port, array $publishedOutsideIpv6): mixed
    {
        if (! self::isIpv6Wildcard($port) || isset($publishedOutsideIpv6[self::anyHostPort($port) ?? 0])) {
            return $port;
        }
        if (is_string($port)) {
            return (string) preg_replace(self::IPV6_WILDCARD, '', $port);
        }
        unset($port['host_ip']);

        return $port;
    }

    /** One port entry with its host port defaulted and, unless kept, its loopback host dropped. */
    private static function withReachableHost(mixed $port, bool $keepLoopback): mixed
    {
        if (is_string($port)) {
            $port = self::withDefaultedHostPort($port);

            return $keepLoopback ? $port : self::withoutLoopbackHost($port);
        }
        if (! $keepLoopback && is_array($port) && isset($port['host_ip']) && self::isLoopbackHost((string) $port['host_ip'])) {
            unset($port['host_ip']);
        }

        return $port;
    }

    /**
     * Whether a service publishes a port the site could be served on, once
     * its host-port variables are defaulted.
     *
     * @param  array<string, mixed>  $service
     */
    public static function publishesWebPort(array $service): bool
    {
        $ports = self::withHostNetworkPortPublished($service)['ports'] ?? null;
        foreach (is_array($ports) ? $ports : [] as $port) {
            $mapping = PortMapping::parse(is_string($port) ? self::withDefaultedHostPort($port) : $port);
            if ($mapping !== null && ! InternalPorts::coversBinding($mapping, $service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `${HTTP_WEB_PORT}:80` with the variable unset publishes on a random host port, so it defaults to
     * the container port, `${HTTP_WEB_PORT:-80}:80`, and an operator who sets it still chooses.
     */
    private static function withDefaultedHostPort(string $port): string
    {
        $parts = self::splitFields(trim($port));
        $count = count($parts);
        if ($count < 2 || $count > 3) {
            return $port;
        }
        $host = trim($parts[$count - 2]);
        $container = explode('/', $parts[$count - 1])[0];
        if (preg_match('/^\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?$/', $host, $m) !== 1 || ! ctype_digit(trim($container))) {
            return $port;
        }
        $parts[$count - 2] = '${'.$m[1].':-'.trim($container).'}';

        return implode(':', $parts);
    }

    /** The host port an entry publishes, on whatever address. */
    private static function anyHostPort(mixed $port): ?int
    {
        if (is_string($port)) {
            $port = (string) preg_replace('/^\s*\[[^\]]*\]:/', '', self::withDefaultedHostPort($port));
            $parts = self::splitFields(trim($port));
            if (count($parts) === 3) {
                array_shift($parts);
                $port = implode(':', $parts);
            }
        } elseif (is_array($port)) {
            unset($port['host_ip']);
        }

        return PortMapping::parse($port)?->hostPort;
    }

    /** `[::]:8080:80`, or the long form with `host_ip: "::"`. */
    private static function isIpv6Wildcard(mixed $port): bool
    {
        if (is_string($port)) {
            return preg_match(self::IPV6_WILDCARD, $port) === 1;
        }

        return is_array($port) && trim((string) ($port['host_ip'] ?? ''), '[] ') === '::';
    }

    /**
     * A short-syntax entry without its loopback host, ports as written. Only a bracketed IPv6 host or
     * the brace-aware three-field form carries a host.
     */
    private static function withoutLoopbackHost(string $port): string
    {
        $port = trim($port);

        if (preg_match('/^\[([^\]]*)\]:(\d.*)$/', $port, $m) === 1) {
            return self::isLoopbackHost($m[1]) ? $m[2] : $port;
        }

        $parts = self::splitFields($port);
        if (count($parts) !== 3 || ! self::isLoopbackHost(EnvVarDefault::resolve($parts[0]))) {
            return $port;
        }

        return $parts[1].':'.$parts[2];
    }

    /**
     * A compose port entry's colon-separated fields.
     *
     * @return list<string>
     */
    private static function splitFields(string $port): array
    {
        return PortMapping::splitFields($port);
    }

    private static function isLoopbackHost(string $host): bool
    {
        return PortMapping::isLoopbackHost($host);
    }

    /**
     * Top-level volumes mount a path of their own, and a service only names them, so its mount
     * checks never see it: each keeps its name and loses `driver_opts` unless they are a tmpfs.
     *
     * @param  array<string, mixed>  $compose
     * @return array{0: array<string, mixed>, 1: list<string>} the file, and what was removed
     */
    public static function withoutVolumeDriverOptions(array $compose): array
    {
        $removed = [];
        foreach ((is_array($compose['volumes'] ?? null) ? $compose['volumes'] : []) as $name => $volume) {
            $options = is_array($volume) ? ($volume['driver_opts'] ?? null) : null;
            if ($options !== null && ! self::isTmpfsDriverOptions($options)) {
                unset($compose['volumes'][$name]['driver_opts']);
                $removed[] = "volume {$name}: driver_opts";
            }
        }

        return [$compose, $removed];
    }

    private static function isTmpfsDriverOptions(mixed $options): bool
    {
        return is_array($options)
            && strtolower(trim((string) ($options['type'] ?? ''))) === 'tmpfs'
            && preg_match('/(^|,)\s*r?bind\s*(,|$)/', (string) ($options['o'] ?? '')) !== 1;
    }

    /**
     * Only the isolation part of {@see harden()}: no limits or defaults, so a
     * compose override that sets none does not start overriding its base.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    public static function withoutEscapes(array $service, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($service[$key]);
        }
        $service = self::withSafeCapabilities($service);
        $service = self::withoutMemlockUlimit($service);
        $service = self::withNofileWithinAccount($service);
        $service = self::withNamespacedSysctls($service);
        if (($service['network_mode'] ?? null) === 'host') {
            unset($service['network_mode']);
        }

        return self::withoutForbiddenMounts($service, $env, $accountUser, $projectDir);
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    private static function withoutForbiddenMounts(array $service, array $env, ?string $accountUser, ?string $projectDir): array
    {
        if (! is_array($service['volumes'] ?? null)) {
            return $service;
        }
        $kept = array_values(array_filter(
            $service['volumes'],
            static fn ($volume): bool => ! MountSourcePolicy::isForbiddenMount($volume, $env, $accountUser, $projectDir)
        ));

        return self::withListOrWithout($service, 'volumes', $kept);
    }

    /**
     * $service with $key set to $value, or without $key when $value is empty:
     * an empty PHP array dumps as `{  }`, a map Compose refuses where it wants a list.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withListOrWithout(array $service, string $key, mixed $value): array
    {
        if ($value === []) {
            unset($service[$key]);
        } else {
            $service[$key] = $value;
        }

        return $service;
    }

    /**
     * The service's mounts {@see withoutEscapes()} removes, as written.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return list<string>
     */
    public static function forbiddenMounts(array $service, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        $forbidden = [];
        foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
            if (MountSourcePolicy::isForbiddenMount($volume, $env, $accountUser, $projectDir)) {
                $forbidden[] = is_string($volume) ? $volume : (string) json_encode($volume, JSON_UNESCAPED_SLASHES);
            }
        }

        return $forbidden;
    }

    /**
     * Secrets/configs whose `file:` is or interpolates to a forbidden source, which Compose bind-mounts into
     * each service naming it; removed with their references, or Compose fails "refers to undefined secret".
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, list<?string>|string>  $env
     * @return array{0: array<string, mixed>, 1: list<string>} the file, and what was removed
     */
    public static function withoutUnsafeFileSources(array $compose, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        $removed = [];
        foreach (['secrets', 'configs'] as $section) {
            [$compose, $removedFromSection] = self::withoutUnsafeFileSourcesIn($section, $compose, $env, $accountUser, $projectDir);
            $removed = [...$removed, ...$removedFromSection];
        }

        return [$compose, $removed];
    }

    /**
     * One top-level `secrets:` or `configs:` block; an override's tag on it
     * (`!override`) is kept and is no way around the check.
     *
     * @param  array<string, mixed>  $compose
     * @param  array<string, list<?string>|string>  $env
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function withoutUnsafeFileSourcesIn(string $section, array $compose, array $env, ?string $accountUser, ?string $projectDir): array
    {
        $block = $compose[$section] ?? null;
        $entries = self::untagged($block);
        if (! is_array($entries)) {
            return [$compose, []];
        }
        $names = [];
        $removed = [];
        foreach ($entries as $name => $entry) {
            $file = self::fileOfEntry($entry);
            if ($file !== null && MountSourcePolicy::isForbiddenSourceIn(MountSourcePolicy::asPath($file), $env, $accountUser, $projectDir)) {
                unset($entries[$name]);
                $names[] = (string) $name;
                $removed[] = rtrim($section, 's')." {$name}: file {$file}";
            }
        }
        if ($names !== []) {
            $compose[$section] = self::retagged(self::tagOf($block), $entries);
            $compose = self::withoutReferencesTo($compose, $section, $names);
        }

        return [$compose, $removed];
    }

    /** A secret's or config's `file:`, or null when it names none. */
    private static function fileOfEntry(mixed $entry): ?string
    {
        $entry = self::untagged($entry);
        $file = is_array($entry) ? ($entry['file'] ?? null) : null;

        return is_string($file) ? $file : null;
    }

    /**
     * Each service's `secrets:`/`configs:` without the named entries, in both
     * the short (`- pw`) and the long (`- source: pw`) form.
     *
     * @param  array<string, mixed>  $compose
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private static function withoutReferencesTo(array $compose, string $section, array $names): array
    {
        if (! is_array($compose['services'] ?? null)) {
            return $compose;
        }
        foreach ($compose['services'] as $serviceName => $service) {
            $pruned = self::serviceWithoutReferencesTo($service, $section, $names);
            if ($pruned !== null) {
                $compose['services'][$serviceName] = $pruned;
            }
        }

        return $compose;
    }

    /**
     * The service without references to $names, or null when it had none.
     *
     * @param  list<string>  $names
     */
    private static function serviceWithoutReferencesTo(mixed $taggedService, string $section, array $names): mixed
    {
        $service = self::untagged($taggedService);
        $taggedList = is_array($service) ? ($service[$section] ?? null) : null;
        $list = self::untagged($taggedList);
        if (! is_array($list)) {
            return null;
        }
        $kept = array_values(array_filter(
            $list,
            static fn (mixed $ref): bool => ! in_array(is_array($ref) ? ($ref['source'] ?? null) : $ref, $names, true)
        ));
        if (count($kept) === count($list)) {
            return null;
        }
        $service = self::withListOrWithout($service, $section, $kept === [] ? [] : self::retagged(self::tagOf($taggedList), $kept));

        return self::retagged(self::tagOf($taggedService), $service);
    }

    private static function tagOf(mixed $value): ?string
    {
        return $value instanceof TaggedValue ? $value->getTag() : null;
    }

    private static function untagged(mixed $value): mixed
    {
        return $value instanceof TaggedValue ? $value->getValue() : $value;
    }

    private static function retagged(?string $tag, mixed $value): mixed
    {
        return $tag === null ? $value : new TaggedValue($tag, $value);
    }

    /**
     * True when the service asks for a locked-memory ulimit, which
     * {@see withoutEscapes()} removes.
     *
     * @param  array<string, mixed>  $service
     */
    public static function requestsMemlockUlimit(array $service): bool
    {
        return is_array($service['ulimits'] ?? null) && array_key_exists('memlock', $service['ulimits']);
    }

    /**
     * An account cannot raise RLIMIT_MEMLOCK above its own 8 MB, so runc refuses Elasticsearch's
     * documented `memlock: -1`; dropping it leaves the account's limit, which any request gets anyway.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withoutMemlockUlimit(array $service): array
    {
        if (! self::requestsMemlockUlimit($service)) {
            return $service;
        }

        unset($service['ulimits']['memlock']);

        return self::withListOrWithout($service, 'ulimits', $service['ulimits']);
    }

    /**
     * `nofile` above the account's own hard limit lowered to it, so the service gets what it could have
     * anyway instead of a raw OCI error. -1 (unlimited) counts as above.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withNofileWithinAccount(array $service): array
    {
        $nofile = is_array($service['ulimits'] ?? null) ? ($service['ulimits']['nofile'] ?? null) : null;
        if (is_array($nofile)) {
            foreach (['soft', 'hard'] as $bound) {
                if (isset($nofile[$bound]) && is_numeric($nofile[$bound])) {
                    $nofile[$bound] = self::nofileWithinAccount((int) $nofile[$bound]);
                }
            }
        } elseif (is_numeric($nofile)) {
            $nofile = self::nofileWithinAccount((int) $nofile);
        } else {
            return $service;
        }
        $service['ulimits']['nofile'] = $nofile;

        return $service;
    }

    private static function nofileWithinAccount(int $value): int
    {
        return $value < 0 || $value > self::MAX_NOFILE ? self::MAX_NOFILE : $value;
    }

    /**
     * Keeps the `cap_add` entries in {@see DEFAULT_CAPABILITIES}, so a service that drops everything and
     * adds back CHOWN/SETUID/SETGID can still drop to its own user. ALL, SYS_ADMIN and the rest go.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withSafeCapabilities(array $service): array
    {
        if (! array_key_exists('cap_add', $service)) {
            return $service;
        }

        $requested = is_string($service['cap_add']) ? [$service['cap_add']] : (array) $service['cap_add'];

        return self::withListOrWithout($service, 'cap_add', self::defaultCapabilitiesIn($requested));
    }

    /**
     * @param  array<mixed>  $requested
     * @return list<string>
     */
    private static function defaultCapabilitiesIn(array $requested): array
    {
        $kept = [];
        foreach ($requested as $capability) {
            if (! is_string($capability)) {
                continue;
            }
            $bare = preg_replace('/^CAP_/', '', strtoupper(trim($capability)));
            if (in_array($bare, self::DEFAULT_CAPABILITIES, true)) {
                $kept[] = trim($capability);
            }
        }

        return $kept;
    }

    /**
     * `sysctls` as a map or as `key=value` entries, keeping only
     * {@see NAMESPACED_SYSCTLS}; dropped entirely when nothing is left.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withNamespacedSysctls(array $service): array
    {
        if (! array_key_exists('sysctls', $service)) {
            return $service;
        }

        $sysctls = (array) $service['sysctls'];
        $kept = [];
        foreach ($sysctls as $key => $value) {
            if (in_array(self::sysctlName($key, $value), self::NAMESPACED_SYSCTLS, true)) {
                $kept[$key] = $value;
            }
        }

        return self::withListOrWithout($service, 'sysctls', array_is_list($sysctls) ? array_values($kept) : $kept);
    }

    /**
     * Skipped for a service sharing another's network namespace, which cannot
     * set net.* sysctls, and one that sets the range itself.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withPingGroupRange(array $service): array
    {
        if (self::sharesAnotherNetworkNamespace($service)) {
            return $service;
        }

        $sysctls = $service['sysctls'] ?? [];
        if (! is_array($sysctls) || self::setsSysctl($sysctls, 'net.ipv4.ping_group_range')) {
            return $service;
        }

        if ($sysctls !== [] && array_is_list($sysctls)) {
            $sysctls[] = 'net.ipv4.ping_group_range='.self::PING_GROUP_RANGE;
        } else {
            $sysctls['net.ipv4.ping_group_range'] = self::PING_GROUP_RANGE;
        }
        $service['sysctls'] = $sysctls;

        return $service;
    }

    /** @param array<string, mixed> $service */
    private static function sharesAnotherNetworkNamespace(array $service): bool
    {
        return preg_match('/^(service|container):/', (string) ($service['network_mode'] ?? '')) === 1;
    }

    /** @param array<mixed> $sysctls */
    private static function setsSysctl(array $sysctls, string $name): bool
    {
        foreach ($sysctls as $key => $value) {
            if (self::sysctlName($key, $value) === $name) {
                return true;
            }
        }

        return false;
    }

    /** A sysctl's name, from a map key or a `key=value` list entry. */
    private static function sysctlName(int|string $key, mixed $value): string
    {
        return trim(is_string($key) ? $key : (is_string($value) ? explode('=', $value, 2)[0] : ''));
    }

    /**
     * `unless-stopped` for a service that publishes a port and names no policy; one publishing none,
     * such as a `wp shell` helper, keeps compose's default and exits instead of restarting forever.
     *
     * @see ComposeHarden::applyReporting() which gives the policy to services others depend on
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    public static function withRestartPolicy(array $service): array
    {
        $restart = $service['restart'] ?? null;
        if ($restart !== null && $restart !== '' && $restart !== false) {
            return $service;
        }
        if (empty($service['ports'])) {
            unset($service['restart']);
        } else {
            $service['restart'] = 'unless-stopped';
        }

        return $service;
    }

    /**
     * Docker's json-file driver keeps a container's log forever, inside the account's quota. A service
     * naming another driver, or sizing its own, is left as it is.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    public static function withLogRotation(array $service): array
    {
        $logging = $service['logging'] ?? null;
        if ($logging === null) {
            $service['logging'] = ['driver' => 'json-file', 'options' => self::LOG_OPTIONS];

            return $service;
        }
        $driver = is_array($logging) ? ($logging['driver'] ?? 'json-file') : null;
        if ($driver !== 'json-file') {
            return $service;
        }
        $options = is_array($logging['options'] ?? null) ? $logging['options'] : [];
        if (! isset($options['max-size'])) {
            $service['logging']['options'] = $options + self::LOG_OPTIONS;
        }

        return $service;
    }

    /**
     * `deploy.resources` moved into `mem_limit` / `cpus` / `pids_limit`: both with different values fail
     * the project with `can't set distinct values`. Other `deploy:` keys are Swarm's and inert here.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withoutDeployResources(array $service): array
    {
        $deploy = $service['deploy'] ?? null;
        if (! is_array($deploy) || ! is_array($deploy['resources'] ?? null)) {
            return $service;
        }

        $service = self::withResourcesFrom($service, $deploy['resources']);
        unset($deploy['resources']);

        return self::withListOrWithout($service, 'deploy', $deploy);
    }

    /**
     * `deploy.resources` limits and memory reservation as service keys, where
     * the service does not already set them.
     *
     * @param  array<string, mixed>  $service
     * @param  array<mixed>  $resources
     * @return array<string, mixed>
     */
    private static function withResourcesFrom(array $service, array $resources): array
    {
        $limits = is_array($resources['limits'] ?? null) ? $resources['limits'] : [];
        foreach (['memory' => 'mem_limit', 'cpus' => 'cpus', 'pids' => 'pids_limit'] as $from => $to) {
            if (isset($limits[$from]) && ! isset($service[$to])) {
                $service[$to] = $limits[$from];
            }
        }

        $reservation = $resources['reservations']['memory'] ?? null;
        if ($reservation !== null && ! isset($service['mem_reservation'])) {
            $service['mem_reservation'] = $reservation;
        }

        return $service;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    private static function withMemoryLimit(string $name, array $service, ?int $accountMemoryMb = null, array $env = []): array
    {
        if (! isset($service['mem_limit']) && ! isset($service['mem_reservation'])) {
            $service['mem_limit'] = ServiceLimits::memoryFor($name, $service, $accountMemoryMb);
        }

        return self::withMemoryHeldToAccount($service, $accountMemoryMb, $env);
    }

    /**
     * A limit or reservation above the account's is held to what the account leaves its services, so the
     * container's own cgroup, and the heap sized from it, match what it can really use.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    private static function withMemoryHeldToAccount(array $service, ?int $accountMemoryMb, array $env): array
    {
        foreach (['mem_limit', 'mem_reservation'] as $key) {
            if (isset($service[$key])) {
                $service[$key] = self::memoryHeldToAccount($service[$key], $accountMemoryMb, $env);
            }
        }

        return $service;
    }

    /**
     * An interpolated size is held only when every value Compose can give it is above the ceiling.
     *
     * @param  mixed  $limit
     * @param  array<string, list<?string>|string>  $env
     * @return mixed
     */
    private static function memoryHeldToAccount($limit, ?int $accountMemoryMb, array $env)
    {
        if (! self::isInterpolated($limit)) {
            return ServiceLimits::heldToAccount($limit, $accountMemoryMb);
        }

        $candidates = ComposeInterpolation::candidates($limit, $env) ?? [];
        $held = array_map(static fn (string $size) => ServiceLimits::heldToAccount($size, $accountMemoryMb), $candidates);
        foreach ($candidates as $i => $size) {
            if ($held[$i] === $size) {
                return $limit;
            }
        }

        return $held[0] ?? $limit;
    }

    /**
     * The smallest size an interpolated limit can take, so the heap fits whichever one Compose picks.
     *
     * @param  mixed  $limit
     * @param  array<string, list<?string>|string>  $env
     * @return mixed
     */
    private static function smallestMemory($limit, array $env)
    {
        if (! self::isInterpolated($limit)) {
            return $limit;
        }

        $sizes = array_map(ServiceLimits::toMegabytes(...), ComposeInterpolation::candidates($limit, $env) ?? []);

        return $sizes === [] || in_array(null, $sizes, true) ? $limit : min($sizes).'m';
    }

    /** @param  mixed  $value */
    private static function isInterpolated($value): bool
    {
        return is_string($value) && str_contains($value, '$');
    }

    /**
     * For a Node service or a local build (stray NODE_OPTIONS elsewhere are inert), appended to the
     * image's own NODE_OPTIONS (Yarn PnP's `--require`), since an `environment:` one replaces it.
     *
     * @param  array<string, mixed>  $service
     * @param  (callable(string): list<string>)|null  $imageEnvironment  an image's `Config.Env`
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    private static function withNodeHeapCap(string $name, array $service, ?int $accountMemoryMb = null, ?callable $imageEnvironment = null, array $env = []): array
    {
        if (ServiceEnvironment::hasKey($service['environment'] ?? null, 'NODE_OPTIONS')) {
            return $service;
        }
        if (! self::shouldCapNodeHeap($name, $service)) {
            return $service;
        }

        $own = $imageEnvironment === null ? null : self::imageNodeOptions($service, $imageEnvironment);
        if ($own !== null && str_contains($own, '--max-old-space-size')) {
            return $service;
        }

        $limit = $service['mem_limit'] ?? $service['mem_reservation']
            ?? ServiceLimits::memoryFor($name, $service, $accountMemoryMb);
        $cap = '--max-old-space-size='.ServiceLimits::nodeHeapMbFor(self::smallestMemory($limit, $env));
        $service['environment'] = ServiceEnvironment::withDefaults(
            $service['environment'] ?? [],
            ['NODE_OPTIONS' => self::nodeOptionsWithCap($own, $cap)]
        );

        return $service;
    }

    /** The image's own options escaped as `$$`, which compose would interpolate otherwise, then the cap. */
    private static function nodeOptionsWithCap(?string $imageOptions, string $cap): string
    {
        return $imageOptions === null || $imageOptions === '' ? $cap : str_replace('$', '$$', $imageOptions).' '.$cap;
    }

    /**
     * NODE_OPTIONS the service's image sets, or null when none, built locally or unreadable. A
     * `name:tag@digest` reference is looked up by its digest alone.
     *
     * @param  array<string, mixed>  $service
     * @param  callable(string): list<string>  $imageEnvironment
     */
    private static function imageNodeOptions(array $service, callable $imageEnvironment): ?string
    {
        $image = $service['image'] ?? null;
        if (! is_string($image) || $image === '' || isset($service['build'])) {
            return null;
        }
        $image = (string) preg_replace(self::TAG_BEFORE_DIGEST, '', $image);
        foreach ($imageEnvironment($image) as $line) {
            if (is_string($line) && str_starts_with($line, 'NODE_OPTIONS=')) {
                return trim(substr($line, strlen('NODE_OPTIONS=')));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function withProcessLimits(string $name, array $service): array
    {
        if (! isset($service['cpus']) && ! isset($service['cpu_count'])) {
            if (self::needsStartPeriodUnderCap($service)) {
                $service['healthcheck']['start_period'] = self::CAPPED_START_PERIOD;
            }
            $service['cpus'] = self::isDatabase($name, $service) ? self::DATABASE_CPUS : self::APPLICATION_CPUS;
        }
        $service['pids_limit'] ??= self::PIDS_LIMIT;

        return $service;
    }

    /**
     * A healthcheck tuned for an unthrottled container on a CPU-capped service (`retries: 1`, no start
     * period may never pass under 0.5 CPU); a start period fixes it, as probes failing in it do not count.
     *
     * @param  array<string, mixed>  $service
     */
    public static function needsStartPeriodUnderCap(array $service): bool
    {
        $check = $service['healthcheck'] ?? null;
        if (isset($service['cpus']) || isset($service['cpu_count']) || ! is_array($check)
            || ! empty($check['disable']) || isset($check['start_period'])) {
            return false;
        }
        $test = $check['test'] ?? null;

        return ! empty($test) && strtoupper(is_array($test) ? (string) ($test[0] ?? '') : (string) $test) !== 'NONE';
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function shouldCapNodeHeap(string $name, array $service): bool
    {
        if (self::isDatabase($name)) {
            return false;
        }

        return self::isNodeService($name, $service) || ! self::hasKnownNonNodeImage($service);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function isNodeService(string $name, array $service): bool
    {
        $haystack = strtolower(implode(' ', [
            $name,
            is_string($service['image'] ?? null) ? $service['image'] : '',
            ComposeCommand::asString($service['command'] ?? null),
            ComposeCommand::asString($service['entrypoint'] ?? null),
        ]));

        return preg_match(self::NODE_COMMAND_PATTERN, $haystack) === 1;
    }

    /**
     * An `image:` naming a runtime that is definitely not Node; no `image:` means a local build, which
     * tells us nothing.
     *
     * @param  array<string, mixed>  $service
     */
    private static function hasKnownNonNodeImage(array $service): bool
    {
        $image = strtolower(is_string($service['image'] ?? null) ? $service['image'] : '');
        if ($image === '') {
            return false;
        }

        return SidecarEngine::isKnownDatastore('', $service)
            || preg_match(self::NON_NODE_IMAGE_PATTERN, $image) === 1;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function isDatabase(string $name, array $service = []): bool
    {
        return SidecarEngine::isKnownDatastore($name, $service);
    }
}
