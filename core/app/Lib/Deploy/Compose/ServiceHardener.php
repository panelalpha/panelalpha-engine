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
 * Makes one service from a customer's compose file safe to run inside an
 * account: removes what would escape the isolation boundary or reach the inner
 * Docker daemon, and caps what it may consume.
 */
final class ServiceHardener
{
    /**
     * Options that escape isolation or hand over the daemon. None of them is
     * ever needed by an application service.
     *
     * The second half of the list was added after a hostile compose file was
     * run through this class and came out still carrying `cap_add: [ALL]`,
     * `security_opt: [seccomp:unconfined]` and `userns_mode: host`: dropping
     * `privileged` means little while the capabilities it implies can be asked
     * for one at a time. This is still a denylist, so a compose key nobody has
     * thought about yet passes -- an allowlist is the real answer.
     * `cap_add` is not here: it is allowlisted by {@see withSafeCapabilities()}.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        // The run file resolves `extends` before hardening; any left here came
        // through a path with no file reader (a compose override, a harvested
        // sidecar), where an unresolved `extends` would merge in an unhardened
        // service. Drop it rather than let Compose pull that service in.
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
        // Opt out of the account's cgroup view, its runtime or its OOM
        // handling; storage_opt only fails off xfs+pquota.
        'cgroupns_mode',
        'runtime',
        'oom_kill_disable',
        'oom_score_adj',
        'storage_opt',
    ];

    /**
     * Docker's default capability set, plus NET_ADMIN. Re-adding one after
     * `cap_drop: [ALL]` never exceeds a default container's reach, so these
     * are all `cap_add` may keep.
     *
     * @var list<string>
     */
    private const DEFAULT_CAPABILITIES = [
        'CHOWN', 'DAC_OVERRIDE', 'FSETID', 'FOWNER', 'MKNOD', 'NET_RAW', 'SETGID',
        'SETUID', 'SETFCAP', 'SETPCAP', 'NET_BIND_SERVICE', 'SYS_CHROOT', 'KILL', 'AUDIT_WRITE',
        // Not a default, but it only administers the service's own network
        // namespace, and images whose binaries carry it as a file capability
        // cannot exec them without it (EPERM at execve).
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
     * Docker's default lets any group open ICMP sockets, but a daemon in a user
     * namespace (the account's) skips it, so non-root ping fails. The upper end
     * is the account's own gid range.
     */
    private const PING_GROUP_RANGE = '0 65535';

    /** @var list<string> */
    private const DOCKER_SOCKETS = ['/var/run/docker.sock', '/run/docker.sock'];

    /**
     * The socket itself, and the directories it sits in. Matching only the
     * exact path left `- /var/run:/var/run` as a way to mount the socket
     * without naming it.
     */
    private const DOCKER_SOCKET_PATTERN = '#(^|:)\s*/(?:var/)?run(?:/docker\.sock)?(?:/)?(?::|$)#';

    /** The account's own writable tree, trusted like `leavesProject()` documents. */
    private const PANELALPHA_DIR = '.panelalpha';

    /**
     * Host paths an application service is never given. `/` covers the whole
     * filesystem; the rest are the parts of it that carry the daemon's state
     * or the host's identity. "Host" is the account container: its /home
     * holds the inner daemon's data-root (~/docker), /run the s6 scan dir,
     * /var the crontabs and /usr the binaries s6 runs as root.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SOURCE_PREFIXES = [
        // The account container's own boot scripts (the egress guard among
        // them), and /run: the inner daemon's and containerd's sockets, s6's
        // scan directory /run/service.
        '/entrypoint.d',
        '/entrypoint.sh',
        '/run',
        '/var/run',
        '/var/lib/docker',
        '/var/lib/containerd',
        '/etc',
        '/boot',
        '/sys',
        '/proc',
        '/dev',
        '/home',
        '/root',
        '/usr',
        // usr-merged: symlinks into /usr, and Docker follows a bind source's symlink.
        '/bin',
        '/sbin',
        '/lib',
        '/lib32',
        '/lib64',
        '/libx32',
        '/opt',
        '/var',
    ];

    /**
     * The account's hard RLIMIT_NOFILE (Docker's default, measured in an
     * account). runc refuses more: "error setting rlimit type 7".
     */
    private const MAX_NOFILE = 524288;

    private const NODE_COMMAND_PATTERN = '/\b(node|nodejs|npm|npx|pnpm|yarn|bun)\b/';

    /** Names of languages and servers — vocabulary, not a product catalogue. */
    private const NON_NODE_IMAGE_PATTERN = '#\b(nginx|httpd|caddy|traefik|haproxy|varnish|envoy'
        . '|php|wordpress|ruby|python|golang|openjdk|eclipse-temurin|rust|dotnet|erlang)\b#';

    private const DATABASE_CPUS = '0.50';

    private const APPLICATION_CPUS = '0.75';

    /** Grace a CPU-capped service's own healthcheck gets when its author gave none. */
    private const CAPPED_START_PERIOD = '60s';

    // 256 starved multi-daemon images (a supervisor plus several daemons and a
    // forked plugin per check exhausted it silently); 1024 clears them and still
    // caps a fork bomb. A per-service pids_limit overrides this default.
    private const PIDS_LIMIT = 1024;

    /**
     * Thread pools sized from the visible core count (OpenMP, MKL, OpenBLAS).
     * A CPU quota does not hide the host's cores, so torch under `cpus: 0.75`
     * ran one thread per host core and was ~12x slower than with one.
     *
     * @var list<string>
     */
    private const THREAD_POOL_VARIABLES = ['OMP_NUM_THREADS', 'MKL_NUM_THREADS', 'OPENBLAS_NUM_THREADS'];

    private const LEGACY_POSTGRES_DATA = '/var/lib/postgresql/data';

    /** @var list<string> */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * @param array<string, mixed> $service
     * @param array<string, list<?string>|string> $env what compose may interpolate with ({@see ComposeInterpolation})
     * @return array<string, mixed>
     */
    public static function harden(
        string $name,
        array $service,
        ?int $accountMemoryMb = null,
        bool $keepLoopbackPorts = false,
        array $env = [],
        ?string $accountUser = null,
        ?string $projectDir = null
    ): array {
        $service = self::withHostNetworkPortPublished($service);
        $service = self::withoutEscapes($service, $env, $accountUser, $projectDir);
        $service = self::withRestartPolicy($service);
        $service = self::withoutDeployResources($service);
        $service = self::withMemoryLimit($name, $service, $accountMemoryMb);
        $service = self::withNodeHeapCap($name, $service, $accountMemoryMb);
        $service = self::withReachablePublishedPorts($service, $keepLoopbackPorts);
        $service = self::withLegacyPostgresDataDir($service);
        $service = self::withPingGroupRange($service);

        $service = self::withProcessLimits($name, $service);

        return self::isDatabase($name, $service) ? $service : self::withThreadPoolsSizedToCpus($service);
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withThreadPoolsSizedToCpus(array $service): array
    {
        $cpus = EnvVarDefault::resolve(trim((string) ($service['cpus'] ?? $service['cpu_count'] ?? '')));
        if (!is_numeric($cpus) || (float) $cpus <= 0) {
            return $service;
        }

        $threads = (string) max(1, (int) ceil((float) $cpus));
        $defaults = [];
        foreach (self::THREAD_POOL_VARIABLES as $variable) {
            if (!ServiceEnvironment::hasKey($service['environment'] ?? null, $variable)) {
                $defaults[$variable] = $threads;
            }
        }
        if ($defaults !== []) {
            $service['environment'] = ServiceEnvironment::withDefaults($service['environment'] ?? [], $defaults);
        }

        return $service;
    }

    /**
     * `network_mode: host` is removed by {@see withoutEscapes()}, and a service
     * that relied on it publishes nothing: Hypermind listens on its `PORT=3000`
     * and the domain 502'd. The port its `PORT` env names is published instead.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withHostNetworkPortPublished(array $service): array
    {
        if (($service['network_mode'] ?? null) !== 'host' || !empty($service['ports']) || !empty($service['expose'])) {
            return $service;
        }
        $port = self::environmentValue($service['environment'] ?? null, 'PORT');
        if ($port === null || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return $service;
        }
        $service['ports'] = [$port . ':' . $port];

        return $service;
    }

    /**
     * One variable from a service's `environment:`, list or map form.
     */
    private static function environmentValue(mixed $environment, string $name): ?string
    {
        if (!is_array($environment)) {
            return null;
        }
        foreach ($environment as $key => $value) {
            if (is_int($key) && is_string($value) && str_starts_with($value, $name . '=')) {
                return trim(substr($value, strlen($name) + 1), " \"'");
            }
            if ($key === $name && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * postgres 18+ keeps its data under /var/lib/postgresql/18/docker and
     * refuses to start when /var/lib/postgresql/data is a mount point
     * (docker-library/postgres#1259). Files written for 17 and earlier mount
     * exactly there, and an unpinned `postgres` tag now resolves to 18. Naming
     * the mount as PGDATA skips that check and keeps the data where the volume
     * is; on 17 and earlier it is the image's own default, so nothing changes.
     *
     * @param array<string, mixed> $service
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
            $target = is_array($volume)
                ? (string) ($volume['target'] ?? '')
                : (string) (self::splitFields(EnvVarDefault::resolve(trim((string) $volume)))[1] ?? '');
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

    /**
     * A loopback host in a port entry means "unreachable" here, not "private":
     * the account container is the boundary and the proxy reaches the app across
     * its own network. PortMapping discards the binding, so the app publishes no
     * port the engine can find and detection falls back to a dead default.
     *
     * With $keepLoopback (another service in the file publishes a web port) a
     * loopback binding is a sidecar kept local on purpose, and stays: Poznote's
     * MCP server on `127.0.0.1:8045` next to its web server.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withReachablePublishedPorts(array $service, bool $keepLoopback = false): array
    {
        $ports = $service['ports'] ?? null;
        if (!is_array($ports)) {
            return $service;
        }

        foreach ($ports as $index => $port) {
            if (is_string($port)) {
                $port = self::withDefaultedHostPort($port);
                $ports[$index] = $keepLoopback ? $port : self::withoutLoopbackHost($port);
                continue;
            }
            if ($keepLoopback) {
                continue;
            }
            // The long form says the same thing in a field of its own.
            if (is_array($port) && isset($port['host_ip']) && self::isLoopbackHost((string) $port['host_ip'])) {
                unset($port['host_ip']);
                $ports[$index] = $port;
            }
        }

        $service['ports'] = $ports;

        return $service;
    }

    /**
     * Whether a service publishes a port the site could be served on, once
     * its host-port variables are defaulted.
     *
     * @param array<string, mixed> $service
     */
    public static function publishesWebPort(array $service): bool
    {
        $ports = self::withHostNetworkPortPublished($service)['ports'] ?? null;
        foreach (is_array($ports) ? $ports : [] as $port) {
            $mapping = PortMapping::parse(is_string($port) ? self::withDefaultedHostPort($port) : $port);
            if ($mapping !== null && !InternalPorts::coversBinding($mapping, $service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `${HTTP_WEB_PORT}:80` with the variable unset publishes 80 on a random
     * host port the engine cannot know. It defaults to the container port,
     * `${HTTP_WEB_PORT:-80}:80`, so an operator who sets it still chooses.
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
        if (preg_match('/^\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?$/', $host, $m) !== 1 || !ctype_digit(trim($container))) {
            return $port;
        }
        $parts[$count - 2] = '${' . $m[1] . ':-' . trim($container) . '}';

        return implode(':', $parts);
    }

    private static function withoutLoopbackHost(string $port): string
    {
        $port = trim($port);

        // An IPv6 address is bracketed, so it has to be taken off before the
        // rest can be split on colons at all.
        if (preg_match('/^\[([^\]]*)\]:(\d.*)$/', $port, $m) === 1) {
            return self::isLoopbackHost($m[1]) ? $m[2] : $port;
        }

        // Only the three-part form carries a host address. Split on field
        // colons and not on ones inside `${VAR:-default}`, or
        // `${BIND_ADDRESS:-127.0.0.1}:${PORT:-3000}:3000` counts five fields.
        $parts = self::splitFields($port);
        if (count($parts) !== 3 || !self::isLoopbackHost(EnvVarDefault::resolve($parts[0]))) {
            return $port;
        }

        // The host goes; the ports stay as the file wrote them, so an operator
        // who sets `${PORT}` still chooses the port.
        return $parts[1] . ':' . $parts[2];
    }

    /**
     * A compose port entry's colon-separated fields.
     *
     * @return list<string>
     */
    private static function splitFields(string $port): array
    {
        $fields = [];
        $field = '';
        $depth = 0;
        foreach (str_split($port) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ':' && $depth === 0) {
                $fields[] = $field;
                $field = '';

                continue;
            }
            $field .= $char;
        }
        $fields[] = $field;

        return $fields;
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array(trim($host, '[]'), self::LOOPBACK_HOSTS, true);
    }

    /** A source compose reads as a path, spelled so it is checked as one: `data` is `./data`. */
    private static function asPath(string $source): string
    {
        $source = trim($source);

        return $source === '' || preg_match('#^[./~$]#', $source) === 1 ? $source : './' . $source;
    }

    /**
     * The top-level entries that mount a path of their own: a service only
     * names them, so its mount checks never see the path. A volume keeps its
     * name and loses `driver_opts` unless they are a tmpfs (a bind, overlay or
     * device mount can name any path of the account container); a secret or
     * config whose `file:` is a forbidden source is removed.
     *
     * @param array<string, mixed> $compose
     * @return array{0: array<string, mixed>, 1: list<string>} the file, and what was removed
     */
    public static function withoutHostPathEntries(array $compose): array
    {
        $removed = [];
        foreach ((is_array($compose['volumes'] ?? null) ? $compose['volumes'] : []) as $name => $volume) {
            $options = is_array($volume) ? ($volume['driver_opts'] ?? null) : null;
            if ($options === null) {
                continue;
            }
            $isTmpfs = is_array($options)
                && strtolower(trim((string) ($options['type'] ?? ''))) === 'tmpfs'
                && preg_match('/(^|,)\s*r?bind\s*(,|$)/', (string) ($options['o'] ?? '')) !== 1;
            if (!$isTmpfs) {
                unset($compose['volumes'][$name]['driver_opts']);
                $removed[] = "volume {$name}: driver_opts";
            }
        }
        foreach (['secrets', 'configs'] as $section) {
            foreach ((is_array($compose[$section] ?? null) ? $compose[$section] : []) as $name => $entry) {
                $file = is_array($entry) ? ($entry['file'] ?? null) : null;
                if (is_string($file) && self::isForbiddenSource($file)) {
                    unset($compose[$section][$name]);
                    $removed[] = rtrim($section, 's') . " {$name}: file {$file}";
                }
            }
        }

        return [$compose, $removed];
    }

    /**
     * Only the isolation part of {@see harden()}: no limits or defaults, so a
     * compose override that sets none does not start overriding its base.
     *
     * @param array<string, mixed> $service
     * @param array<string, list<?string>|string> $env
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
        if (is_array($service['volumes'] ?? null)) {
            $kept = array_values(array_filter(
                $service['volumes'],
                static fn ($volume): bool => !self::isForbiddenMount($volume, $env, $accountUser, $projectDir)
            ));
            // Dropped when empty: an empty PHP array dumps as `volumes: {  }` --
            // a map, not a sequence -- and Compose refuses the whole file with
            // `services.<name>.volumes must be a array`.
            if ($kept === []) {
                unset($service['volumes']);
            } else {
                $service['volumes'] = $kept;
            }
        }

        return $service;
    }

    /**
     * The service's mounts {@see withoutEscapes()} removes, as written.
     *
     * @param array<string, mixed> $service
     * @param array<string, list<?string>|string> $env
     * @return list<string>
     */
    public static function forbiddenMounts(array $service, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        $forbidden = [];
        foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
            if (self::isForbiddenMount($volume, $env, $accountUser, $projectDir)) {
                $forbidden[] = is_string($volume) ? $volume : (string) json_encode($volume, JSON_UNESCAPED_SLASHES);
            }
        }

        return $forbidden;
    }

    /**
     * Top-level secrets and configs whose `file:` is a forbidden source, or
     * interpolates to one, removed: Compose bind-mounts that file into every
     * service that names the entry. The services' references to a removed
     * entry go too, or Compose refuses the file as "refers to undefined secret".
     *
     * @param array<string, mixed> $compose
     * @param array<string, list<?string>|string> $env
     * @return array{0: array<string, mixed>, 1: list<string>} the file, and what was removed
     */
    public static function withoutUnsafeFileSources(array $compose, array $env = [], ?string $accountUser = null, ?string $projectDir = null): array
    {
        $removed = [];
        foreach (['secrets', 'configs'] as $section) {
            // An override may tag the block (`!override`); the tag is no way around the check.
            $block = $compose[$section] ?? null;
            $tag = $block instanceof TaggedValue ? $block->getTag() : null;
            $entries = $block instanceof TaggedValue ? $block->getValue() : $block;
            if (!is_array($entries)) {
                continue;
            }
            $names = [];
            foreach ($entries as $name => $entry) {
                $entry = $entry instanceof TaggedValue ? $entry->getValue() : $entry;
                $file = is_array($entry) ? ($entry['file'] ?? null) : null;
                if (is_string($file) && self::isForbiddenSourceIn(self::asPath($file), $env, $accountUser, $projectDir)) {
                    unset($entries[$name]);
                    $names[] = (string) $name;
                    $removed[] = rtrim($section, 's') . " {$name}: file {$file}";
                }
            }
            if ($names !== []) {
                $compose[$section] = $tag === null ? $entries : new TaggedValue($tag, $entries);
                $compose = self::withoutReferencesTo($compose, $section, $names);
            }
        }

        return [$compose, $removed];
    }

    /**
     * Each service's `secrets:`/`configs:` without the named entries, in both
     * the short (`- pw`) and the long (`- source: pw`) form.
     *
     * @param array<string, mixed> $compose
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function withoutReferencesTo(array $compose, string $section, array $names): array
    {
        if (!is_array($compose['services'] ?? null)) {
            return $compose;
        }
        foreach ($compose['services'] as $serviceName => $service) {
            $serviceTag = $service instanceof TaggedValue ? $service->getTag() : null;
            $service = $service instanceof TaggedValue ? $service->getValue() : $service;
            $list = is_array($service) ? ($service[$section] ?? null) : null;
            $listTag = $list instanceof TaggedValue ? $list->getTag() : null;
            $list = $list instanceof TaggedValue ? $list->getValue() : $list;
            if (!is_array($list)) {
                continue;
            }
            $kept = array_values(array_filter($list, static function (mixed $ref) use ($names): bool {
                $source = is_array($ref) ? ($ref['source'] ?? null) : $ref;

                return !is_string($source) || !in_array($source, $names, true);
            }));
            if (count($kept) === count($list)) {
                continue;
            }
            if ($kept === []) {
                unset($service[$section]);
            } else {
                $service[$section] = $listTag === null ? $kept : new TaggedValue($listTag, $kept);
            }
            $compose['services'][$serviceName] = $serviceTag === null ? $service : new TaggedValue($serviceTag, $service);
        }

        return $compose;
    }

    /**
     * True when the service asks for a locked-memory ulimit, which
     * {@see withoutEscapes()} removes.
     *
     * @param array<string, mixed> $service
     */
    public static function requestsMemlockUlimit(array $service): bool
    {
        return is_array($service['ulimits'] ?? null) && array_key_exists('memlock', $service['ulimits']);
    }

    /**
     * An account cannot raise RLIMIT_MEMLOCK above its own 8 MB, so runc refuses
     * to start a service asking for Elasticsearch's documented `memlock: -1`.
     * Dropping it leaves the account's limit, which is what any lower request
     * would have got anyway.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withoutMemlockUlimit(array $service): array
    {
        if (!self::requestsMemlockUlimit($service)) {
            return $service;
        }

        unset($service['ulimits']['memlock']);
        // An empty map would still be valid, but says nothing.
        if ($service['ulimits'] === []) {
            unset($service['ulimits']);
        }

        return $service;
    }

    /**
     * `nofile` above the account's own hard limit lowered to it, as the
     * memlock request is dropped: the service gets what it could have anyway
     * instead of a raw OCI error. -1 (unlimited) counts as above.
     *
     * @param array<string, mixed> $service
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
     * Keeps the `cap_add` entries in {@see DEFAULT_CAPABILITIES}, so a service
     * that drops everything and adds back CHOWN/SETUID/SETGID can still drop to
     * its own user. ALL, SYS_ADMIN and the rest are removed as before.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withSafeCapabilities(array $service): array
    {
        if (!array_key_exists('cap_add', $service)) {
            return $service;
        }

        $requested = is_string($service['cap_add']) ? [$service['cap_add']] : (array) $service['cap_add'];
        $kept = [];
        foreach ($requested as $capability) {
            if (!is_string($capability)) {
                continue;
            }
            $bare = preg_replace('/^CAP_/', '', strtoupper(trim($capability)));
            if (in_array($bare, self::DEFAULT_CAPABILITIES, true)) {
                $kept[] = trim($capability);
            }
        }

        // An empty list would dump as `cap_add: {  }`, a map Compose refuses.
        if ($kept === []) {
            unset($service['cap_add']);
        } else {
            $service['cap_add'] = $kept;
        }

        return $service;
    }

    /**
     * `sysctls` as a map or as `key=value` entries, keeping only
     * {@see NAMESPACED_SYSCTLS}; dropped entirely when nothing is left.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withNamespacedSysctls(array $service): array
    {
        if (!array_key_exists('sysctls', $service)) {
            return $service;
        }

        $sysctls = (array) $service['sysctls'];
        $kept = [];
        foreach ($sysctls as $key => $value) {
            if (in_array(self::sysctlName($key, $value), self::NAMESPACED_SYSCTLS, true)) {
                $kept[$key] = $value;
            }
        }

        if ($kept === []) {
            unset($service['sysctls']);
        } else {
            $service['sysctls'] = array_is_list($sysctls) ? array_values($kept) : $kept;
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withPingGroupRange(array $service): array
    {
        // A service sharing another's network namespace cannot set net.* sysctls.
        if (preg_match('/^(service|container):/', (string) ($service['network_mode'] ?? '')) === 1) {
            return $service;
        }

        $sysctls = $service['sysctls'] ?? [];
        if (!is_array($sysctls)) {
            return $service;
        }
        foreach ($sysctls as $key => $value) {
            if (self::sysctlName($key, $value) === 'net.ipv4.ping_group_range') {
                return $service;
            }
        }

        if ($sysctls !== [] && array_is_list($sysctls)) {
            $sysctls[] = 'net.ipv4.ping_group_range=' . self::PING_GROUP_RANGE;
        } else {
            $sysctls['net.ipv4.ping_group_range'] = self::PING_GROUP_RANGE;
        }
        $service['sysctls'] = $sysctls;

        return $service;
    }

    /** A sysctl's name, from a map key or a `key=value` list entry. */
    private static function sysctlName(int|string $key, mixed $value): string
    {
        return trim(is_string($key) ? $key : (is_string($value) ? explode('=', $value, 2)[0] : ''));
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withRestartPolicy(array $service): array
    {
        $restart = $service['restart'] ?? null;
        if ($restart === null || $restart === '' || $restart === false) {
            $service['restart'] = 'unless-stopped';
        }

        return $service;
    }

    /**
     * `deploy.resources` says the same thing as `mem_limit` / `cpus` /
     * `pids_limit`, and a service carrying both with different values fails the
     * whole project with `can't set distinct values on 'pids_limit' and
     * 'deploy.resources.limits.pids'`. The author's numbers move into the keys
     * used above; other `deploy:` keys are Swarm's and inert here.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withoutDeployResources(array $service): array
    {
        $deploy = $service['deploy'] ?? null;
        if (!is_array($deploy) || !is_array($deploy['resources'] ?? null)) {
            return $service;
        }

        $limits = is_array($deploy['resources']['limits'] ?? null) ? $deploy['resources']['limits'] : [];
        foreach (['memory' => 'mem_limit', 'cpus' => 'cpus', 'pids' => 'pids_limit'] as $from => $to) {
            if (isset($limits[$from]) && !isset($service[$to])) {
                $service[$to] = $limits[$from];
            }
        }

        $reservation = $deploy['resources']['reservations']['memory'] ?? null;
        if ($reservation !== null && !isset($service['mem_reservation'])) {
            $service['mem_reservation'] = $reservation;
        }

        unset($deploy['resources']);
        if ($deploy === []) {
            unset($service['deploy']);
        } else {
            $service['deploy'] = $deploy;
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withMemoryLimit(string $name, array $service, ?int $accountMemoryMb = null): array
    {
        if (!isset($service['mem_limit']) && !isset($service['mem_reservation'])) {
            $service['mem_limit'] = ServiceLimits::memoryFor($name, $service, $accountMemoryMb);
        }

        return $service;
    }

    /**
     * Applied when the service is recognisably Node or builds from source (no
     * `image:` to match on): a stray NODE_OPTIONS on a non-Node process is inert.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withNodeHeapCap(string $name, array $service, ?int $accountMemoryMb = null): array
    {
        if (ServiceEnvironment::hasKey($service['environment'] ?? null, 'NODE_OPTIONS')) {
            return $service;
        }
        if (!self::shouldCapNodeHeap($name, $service)) {
            return $service;
        }

        $limit = $service['mem_limit'] ?? $service['mem_reservation']
            ?? ServiceLimits::memoryFor($name, $service, $accountMemoryMb);
        $service['environment'] = ServiceEnvironment::withDefaults(
            $service['environment'] ?? [],
            ['NODE_OPTIONS' => '--max-old-space-size=' . ServiceLimits::nodeHeapMbFor($limit)]
        );

        return $service;
    }

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withProcessLimits(string $name, array $service): array
    {
        if (!isset($service['cpus']) && !isset($service['cpu_count'])) {
            if (self::needsStartPeriodUnderCap($service)) {
                $service['healthcheck']['start_period'] = self::CAPPED_START_PERIOD;
            }
            $service['cpus'] = self::isDatabase($name, $service) ? self::DATABASE_CPUS : self::APPLICATION_CPUS;
        }
        $service['pids_limit'] ??= self::PIDS_LIMIT;

        return $service;
    }

    /**
     * A healthcheck tuned for an unthrottled container, on a service the CPU cap
     * will slow down: Tiledesk's RabbitMQ (`retries: 1`, no start period) was
     * never healthy under 0.5 CPU. Probes failing in a start period do not count.
     *
     * @param array<string, mixed> $service
     */
    public static function needsStartPeriodUnderCap(array $service): bool
    {
        $check = $service['healthcheck'] ?? null;
        if (isset($service['cpus']) || isset($service['cpu_count']) || !is_array($check)
            || !empty($check['disable']) || isset($check['start_period'])) {
            return false;
        }
        $test = $check['test'] ?? null;

        return !empty($test) && strtoupper(is_array($test) ? (string) ($test[0] ?? '') : (string) $test) !== 'NONE';
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function shouldCapNodeHeap(string $name, array $service): bool
    {
        if (self::isDatabase($name)) {
            return false;
        }

        return self::isNodeService($name, $service) || !self::hasKnownNonNodeImage($service);
    }

    /**
     * @param array<string, mixed> $service
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
     * An `image:` naming a runtime that is definitely not Node. Absence of
     * `image:` means the service builds locally and tells us nothing.
     *
     * @param array<string, mixed> $service
     */
    private static function hasKnownNonNodeImage(array $service): bool
    {
        $image = strtolower(is_string($service['image'] ?? null) ? $service['image'] : '');
        if ($image === '') {
            return false;
        }

        // The catalogue already knows which images are datastores.
        return SidecarEngine::isKnownDatastore('', $service)
            || preg_match(self::NON_NODE_IMAGE_PATTERN, $image) === 1;
    }

    /**
     * @param array<string, mixed> $service
     */
    private static function isDatabase(string $name, array $service = []): bool
    {
        return SidecarEngine::isKnownDatastore($name, $service);
    }

    /**
     * {@see isForbiddenSource()} for a source Compose will interpolate: every
     * value it can take is checked, and one that cannot be told is refused.
     * `${DATA_DIR:-./data}` stays allowed; `${X:-/var/run}` does not.
     *
     * @param array<string, list<?string>|string> $env
     */
    private static function isForbiddenSourceIn(string $source, array $env, ?string $accountUser = null, ?string $projectDir = null): bool
    {
        if (!str_contains($source, '$')) {
            return self::isForbiddenSource($source, $accountUser, $projectDir);
        }
        $candidates = ComposeInterpolation::candidates($source, $env);
        if ($candidates === null) {
            return true;
        }
        foreach ($candidates as $candidate) {
            if (preg_match(self::DOCKER_SOCKET_PATTERN, trim($candidate)) === 1 || self::isForbiddenSource($candidate, $accountUser, $projectDir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $volume
     * @param array<string, list<?string>|string> $env
     */
    private static function isForbiddenMount($volume, array $env = [], ?string $accountUser = null, ?string $projectDir = null): bool
    {
        if (is_string($volume)) {
            if (preg_match(self::DOCKER_SOCKET_PATTERN, $volume) === 1) {
                return true;
            }

            // Only the source side is a host path. A named volume has no
            // leading slash and is not one of these.
            $source = self::splitFields(trim($volume))[0] ?? '';

            return self::isForbiddenSourceIn($source, $env, $accountUser, $projectDir);
        }
        if (!is_array($volume)) {
            return false;
        }

        $source = (string) ($volume['source'] ?? '');
        $target = (string) ($volume['target'] ?? '');
        if (($volume['type'] ?? null) === 'bind') {
            // The long form's bind source is a path even without a leading `./`.
            $source = self::asPath($source);
        }

        // The socket's directory counts here too: `source: /var/run` in the long
        // form hands over the daemon exactly like `/var/run:/var/run` does.
        return in_array($source, self::DOCKER_SOCKETS, true)
            || in_array($target, self::DOCKER_SOCKETS, true)
            || preg_match(self::DOCKER_SOCKET_PATTERN, trim($source)) === 1
            || self::isForbiddenSourceIn($source, $env, $accountUser, $projectDir);
    }

    /**
     * A host path the account's own services never get: `/` itself, or anything
     * under one of {@see FORBIDDEN_SOURCE_PREFIXES}. `/hostfs` bound from `/`
     * was the case that prompted this -- it reads and writes the whole
     * filesystem the service's daemon is running on.
     */
    private static function isForbiddenSource(string $source, ?string $accountUser = null, ?string $projectDir = null): bool
    {
        $source = trim($source);
        // Docker follows symlinks in a bind source, and a checkout keeps them.
        // Skipped for a path that resolves into ~/.panelalpha, relative or
        // absolute: the account already owns that tree outright, and it being
        // account-private (0700) means the engine's own process often cannot
        // even stat into it to tell a symlink from a missing file --
        // LinkedSource::escapes() then has to assume the worst and refuses a
        // bind this function means to allow (leavesProject()'s own docblock).
        $inPanelalpha = str_starts_with($source, '/')
            ? self::isOwnPanelalpha(self::normalisedAbsolute($source), $accountUser)
            : self::relativeFirstSegment($source) === self::PANELALPHA_DIR;
        if ($projectDir !== null && str_starts_with($projectDir, '/') && preg_match('#^[./]#', $source) === 1
            && !$inPanelalpha
            && LinkedSource::escapes($source, $projectDir)
        ) {
            return true;
        }
        // `~` and $HOME are root's home, or the account's, which holds ~/docker.
        if (preg_match('#^(~|\$HOME\b|\$\{HOME\})#', $source) === 1) {
            return true;
        }
        if (str_starts_with($source, '.')) {
            return self::leavesProject($source);
        }
        if (!str_starts_with($source, '/')) {
            return false;
        }
        // Normalised first, so `/home/u/.panelalpha/../docker` is /home/u/docker.
        $source = self::normalisedAbsolute($source);
        if ($source === '/') {
            // The root of the filesystem, the worst one of all.
            return true;
        }
        // The account's own ~/.panelalpha: the one writable tree a rebuild keeps,
        // which recipe hooks record in .env by its absolute path.
        if (self::isOwnPanelalpha($source, $accountUser)) {
            return false;
        }

        foreach (self::FORBIDDEN_SOURCE_PREFIXES as $prefix) {
            if ($source === $prefix || str_starts_with($source, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Whether normalised absolute $source is the account's ~/.panelalpha or under it. */
    private static function isOwnPanelalpha(string $source, ?string $accountUser): bool
    {
        if ($accountUser === null || preg_match('/^[a-z_][a-z0-9_.-]*$/i', $accountUser) !== 1) {
            return false;
        }
        $keep = "/home/{$accountUser}/.panelalpha";

        return $source === $keep || str_starts_with($source, $keep . '/');
    }

    private static function normalisedAbsolute(string $source): string
    {
        $segments = [];
        foreach (explode('/', $source) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * A relative bind resolves against ~/project (the run file sits there), so
     * `../../../etc` is /etc. One level up is the account's home: allowed
     * (`../.panelalpha/...`, WeTTY's `../:/account`), except its docker/
     * data-root.
     */
    private static function leavesProject(string $source): bool
    {
        $first = self::relativeFirstSegment($source);

        // false means more `..` than there were segments to pop: past ~ itself.
        return $first === false || $first === 'docker';
    }

    /**
     * The first path component once `.`/`..` are resolved against `~/project`:
     * a name, null for `~` itself (e.g. `../`), or false for more `..` than
     * there were parts to pop (past `~`).
     */
    private static function relativeFirstSegment(string $source): string|false|null
    {
        $segments = ['project'];
        foreach (explode('/', $source) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return false;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments[0] ?? null;
    }
}
