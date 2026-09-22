<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\EnvVarDefault;
use App\Lib\Deploy\Sidecar\SidecarEngine;

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
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = ['privileged', 'pid', 'ipc', 'uts', 'devices'];

    /** @var list<string> */
    private const DOCKER_SOCKETS = ['/var/run/docker.sock', '/run/docker.sock'];

    private const DOCKER_SOCKET_PATTERN = '#(^|:)\s*/(?:var/)?run/docker\.sock(?::|$)#';

    private const NODE_COMMAND_PATTERN = '/\b(node|nodejs|npm|npx|pnpm|yarn|bun)\b/';

    /** Names of languages and servers — vocabulary, not a product catalogue. */
    private const NON_NODE_IMAGE_PATTERN = '#\b(nginx|httpd|caddy|traefik|haproxy|varnish|envoy'
        . '|php|wordpress|ruby|python|golang|openjdk|eclipse-temurin|rust|dotnet|erlang)\b#';

    private const DATABASE_CPUS = '0.50';

    private const APPLICATION_CPUS = '0.75';

    // 256 starved multi-daemon images (a supervisor plus several daemons and a
    // forked plugin per check exhausted it silently); 1024 clears them and still
    // caps a fork bomb. A per-service pids_limit overrides this default.
    private const PIDS_LIMIT = 1024;

    /** @var list<string> */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    public static function harden(string $name, array $service, ?int $accountMemoryMb = null): array
    {
        $service = self::withoutEscapes($service);
        $service = self::withRestartPolicy($service);
        $service = self::withoutDeployResources($service);
        $service = self::withMemoryLimit($name, $service, $accountMemoryMb);
        $service = self::withNodeHeapCap($name, $service, $accountMemoryMb);
        $service = self::withReachablePublishedPorts($service);

        return self::withProcessLimits($name, $service);
    }

    /**
     * A loopback host in a port entry means "unreachable" here, not "private":
     * the account container is the boundary and the proxy reaches the app across
     * its own network. PortMapping discards the binding, so the app publishes no
     * port the engine can find and detection falls back to a dead default.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withReachablePublishedPorts(array $service): array
    {
        $ports = $service['ports'] ?? null;
        if (!is_array($ports)) {
            return $service;
        }

        foreach ($ports as $index => $port) {
            if (is_string($port)) {
                $ports[$index] = self::withoutLoopbackHost($port);
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

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withoutEscapes(array $service): array
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($service[$key]);
        }
        if (($service['network_mode'] ?? null) === 'host') {
            unset($service['network_mode']);
        }
        if (is_array($service['volumes'] ?? null)) {
            $kept = array_values(array_filter(
                $service['volumes'],
                static fn ($volume): bool => !self::isDockerSocketMount($volume)
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
            $service['cpus'] = self::isDatabase($name, $service) ? self::DATABASE_CPUS : self::APPLICATION_CPUS;
        }
        $service['pids_limit'] ??= self::PIDS_LIMIT;

        return $service;
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
     * @param mixed $volume
     */
    private static function isDockerSocketMount($volume): bool
    {
        if (is_string($volume)) {
            return preg_match(self::DOCKER_SOCKET_PATTERN, $volume) === 1;
        }
        if (!is_array($volume)) {
            return false;
        }

        return in_array((string) ($volume['source'] ?? ''), self::DOCKER_SOCKETS, true)
            || in_array((string) ($volume['target'] ?? ''), self::DOCKER_SOCKETS, true);
    }
}
