<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Port\PortMapping;

/**
 * What a compose file borrows from the host it was written for: an existing
 * reverse proxy and the external network it listens on.
 *
 * Neither exists inside an account. `docker compose up` refuses a network
 * declared external that is not there, and a bundled Traefik would only sit
 * in front of the app where the engine's own proxy already is.
 */
final class HostIngress
{
    /** Last image path segment of a proxy the engine's routing replaces. */
    private const PROXY_IMAGES = ['traefik', 'caddy-docker-proxy'];

    /** Owner-qualified, since a bare `nginx-proxy` may be the app's own nginx. */
    private const PROXY_REPOSITORIES = ['jwilder/nginx-proxy', 'nginxproxy/nginx-proxy'];

    private const ROUTED_PORT_LABEL = '/^traefik\.http\.services\.[^.]+\.loadbalancer\.server\.port$/i';

    private const ROUTER_RULE_LABEL = '/^traefik\.http\.routers\.[^.]+\.rule$/i';

    /** Traefik discovering containers: what makes it a host's ingress. */
    private const CONTAINER_PROVIDER_PATTERN = '/providers[._](docker|swarm)|docker\.sock/i';

    private const FILE_PROVIDER_PATTERN = '/providers[._]file/i';

    /**
     * @param array<string, mixed> $compose
     * @return array{compose: array<string, mixed>, dropped: list<string>} dropped: one log line each
     */
    public static function strip(array $compose): array
    {
        $dropped = [];
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];

        $proxies = self::proxyServices($services);
        foreach ($proxies as $name => $image) {
            unset($services[$name]);
            $dropped[] = "Dropped service {$name} ({$image}): the engine's proxy routes traffic to this app";
        }
        $pathRouted = $proxies === [] ? [] : self::pathRoutedServices($services);
        if ($pathRouted !== []) {
            $dropped[] = 'Traefik routed request paths between services (' . implode(', ', $pathRouted) . '); '
                . "the engine's proxy sends the whole domain to one service, so the others are not reachable from outside";
        }

        $external = self::externalNetworks($compose['networks'] ?? null);
        foreach ($external as $network) {
            unset($compose['networks'][$network]);
            $dropped[] = "Dropped external network {$network}: it exists only on the host this compose file was written for";
        }
        if (isset($compose['networks']) && $compose['networks'] === []) {
            unset($compose['networks']);
        }

        if ($proxies === [] && $external === []) {
            return ['compose' => $compose, 'dropped' => []];
        }

        $droppedNames = array_fill_keys(array_map('strtolower', array_map('strval', array_keys($proxies))), true);
        foreach ($services as $name => $service) {
            if (is_array($service)) {
                $service = ServiceDependencies::withoutDropped($service, $droppedNames);
                if ($proxies !== []) {
                    $service = self::withRoutedPortsExposed($service);
                }
                $services[$name] = self::withoutNetworks($service, $external);
            }
        }
        $compose['services'] = $services;

        return ['compose' => $compose, 'dropped' => $dropped];
    }

    /**
     * Left alone when the proxy is all the file defines: there is nothing
     * else to deploy, and an empty stack fails less clearly than this one.
     *
     * @param array<mixed> $services
     * @return array<string, string> service name => image
     */
    private static function proxyServices(array $services): array
    {
        $proxies = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && self::isIngressProxy($service)) {
                $proxies[(string) $name] = (string) $service['image'];
            }
        }

        return count($proxies) < count($services) ? $proxies : [];
    }

    /**
     * A reverse proxy the engine's own routing replaces, unless it only
     * routes the stack's own paths.
     *
     * @param array<mixed> $service
     */
    public static function isIngressProxy(array $service): bool
    {
        $image = is_string($service['image'] ?? null) ? $service['image'] : '';

        return $image !== '' && self::isProxyImage($image) && !self::isStackRouter($image, $service);
    }

    /**
     * A Traefik that is not a host's ingress: it runs something other than
     * Traefik on the image (a one-shot gate), or routes the stack's own paths
     * from a file provider with no way to discover containers (not-th.re's
     * `/api` -> api, `/` -> ui). Dropping that leaves one service reachable.
     *
     * @param array<string, mixed> $service
     */
    private static function isStackRouter(string $image, array $service): bool
    {
        $segments = explode('/', self::repositoryOf($image));
        if (end($segments) !== 'traefik') {
            return false;
        }
        if (!self::runsTraefik($service)) {
            return true;
        }

        $evidence = implode(' ', [
            ComposeCommand::asString($service['command'] ?? null),
            ComposeCommand::asString($service['entrypoint'] ?? null),
            json_encode($service['environment'] ?? []) ?: '',
            json_encode($service['volumes'] ?? []) ?: '',
        ]);

        return preg_match(self::CONTAINER_PROVIDER_PATTERN, $evidence) !== 1
            && preg_match(self::FILE_PROVIDER_PATTERN, $evidence) === 1;
    }

    /**
     * The image's entrypoint runs Traefik unless the file replaces it, or
     * hands it a command that is not a flag or `traefik` itself.
     *
     * @param array<string, mixed> $service
     */
    private static function runsTraefik(array $service): bool
    {
        $entrypoint = ComposeCommand::asString($service['entrypoint'] ?? null);
        if ($entrypoint !== '') {
            return str_contains(strtolower($entrypoint), 'traefik');
        }
        $command = ltrim(ComposeCommand::asString($service['command'] ?? null));
        if ($command === '') {
            return true;
        }

        return str_starts_with($command, '-') || preg_match('#^(\S*/)?traefik(\s|$)#', $command) === 1;
    }

    /**
     * Services a Traefik router rule sends a path to, which one published
     * port cannot reproduce.
     *
     * @param array<mixed> $services
     * @return list<string>
     */
    private static function pathRoutedServices(array $services): array
    {
        $names = [];
        foreach ($services as $name => $service) {
            $labels = is_array($service) && is_array($service['labels'] ?? null) ? $service['labels'] : [];
            foreach ($labels as $key => $value) {
                if (is_int($key)) {
                    [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
                }
                if (preg_match(self::ROUTER_RULE_LABEL, trim((string) $key)) === 1
                    && preg_match('/\bPath(Prefix)?\(/', (string) $value) === 1) {
                    $names[] = (string) $name;
                    break;
                }
            }
        }

        return $names;
    }

    private static function repositoryOf(string $image): string
    {
        // Digest and tag off: `docker.io/library/traefik:v3.1@sha256:…` -> `docker.io/library/traefik`.
        return strtolower((string) preg_replace(['/@.*$/', '#:[^/]*$#'], '', trim($image)));
    }

    private static function isProxyImage(string $image): bool
    {
        $repository = self::repositoryOf($image);
        $segments = explode('/', $repository);

        if (in_array(end($segments), self::PROXY_IMAGES, true)) {
            return true;
        }
        foreach (self::PROXY_REPOSITORIES as $proxy) {
            if ($repository === $proxy || str_ends_with($repository, '/' . $proxy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The port Traefik's labels routed to, kept as `expose:` so port detection
     * still finds it once the proxy is gone. The labels themselves stay; inert.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function withRoutedPortsExposed(array $service): array
    {
        $routed = self::routedPorts($service['labels'] ?? null);
        if ($routed === []) {
            return $service;
        }

        $declared = [];
        foreach (['ports', 'expose'] as $key) {
            $entries = $service[$key] ?? [];
            foreach (is_array($entries) ? $entries : [$entries] as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping !== null) {
                    $declared[$mapping->hostPort] = true;
                    $declared[$mapping->containerPort ?? $mapping->hostPort] = true;
                }
            }
        }

        $expose = is_array($service['expose'] ?? null) ? array_values($service['expose']) : [];
        foreach ($routed as $port) {
            if (!isset($declared[$port])) {
                $expose[] = (string) $port;
                $declared[$port] = true;
            }
        }
        if ($expose !== []) {
            $service['expose'] = $expose;
        }

        return $service;
    }

    /**
     * `traefik.http.services.<name>.loadbalancer.server.port`, list or map form.
     *
     * @param mixed $labels
     * @return list<int>
     */
    private static function routedPorts($labels): array
    {
        if (!is_array($labels)) {
            return [];
        }

        $ports = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, '');
            }
            $value = trim((string) $value);
            if (preg_match(self::ROUTED_PORT_LABEL, trim((string) $key)) === 1
                && ctype_digit($value) && (int) $value > 0) {
                $ports[(int) $value] = true;
            }
        }

        return array_keys($ports);
    }

    /**
     * `external: true`, or the older `external: {name: …}` form.
     *
     * @param mixed $networks
     * @return list<string>
     */
    private static function externalNetworks($networks): array
    {
        if (!is_array($networks)) {
            return [];
        }

        $external = [];
        foreach ($networks as $name => $network) {
            $flag = is_array($network) ? ($network['external'] ?? null) : null;
            if ($flag === true || is_array($flag)) {
                $external[] = (string) $name;
            }
        }

        return $external;
    }

    /**
     * A service left on no network loses the key, so it joins the project's
     * default network instead of none.
     *
     * @param array<string, mixed> $service
     * @param list<string> $networks
     * @return array<string, mixed>
     */
    private static function withoutNetworks(array $service, array $networks): array
    {
        $declared = $service['networks'] ?? null;
        if ($networks === [] || !is_array($declared)) {
            return $service;
        }

        if (array_is_list($declared)) {
            $kept = array_values(array_filter(
                $declared,
                static fn ($name): bool => !in_array((string) $name, $networks, true)
            ));
        } else {
            $kept = array_diff_key($declared, array_flip($networks));
        }

        if ($kept === []) {
            unset($service['networks']);
        } else {
            $service['networks'] = $kept;
        }

        return $service;
    }
}
