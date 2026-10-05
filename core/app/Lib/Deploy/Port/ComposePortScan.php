<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * The public ports a compose file offers, best first. Both ends of every
 * binding are inspected: a mapping whose container port is well known (5432,
 * 3306) is dropped even when the host port is something else (5434). What was
 * dropped comes back too, under `refused`, so a caller can tell a compose file
 * that offers nothing from one that offers only a database.
 *
 * @psalm-type Refusal = array{port: int, reason: string, service: string}
 * @psalm-type Choice = array{reason: string, alternatives: list<int>}
 * @psalm-type Scan = array{all: list<int>, primary?: int, refused: list<Refusal>}
 */
final class ComposePortScan
{
    /** Ports a web application is most likely to want, in order. */
    private const PREFERRED = [80, 443, 8000, 8001, 8080, 8090, 8443, 3000, 5000, 9000];

    private const DEFAULT_PRIMARY = 8080;

    /** Why the primary port was chosen ({@see choice()}). */
    public const CHOSEN_PREFERRED = 'preferred';
    public const CHOSEN_HEALTHCHECK = 'healthcheck';
    public const CHOSEN_LOWEST = 'lowest';
    public const CHOSEN_SINGLE = 'single';

    /**
     * @param array<string, string> $env the project's `.env`, which compose
     *        interpolates host ports with: `${HTTP_WEB_PORT:-80}:80` publishes
     *        8040 when `.env` says HTTP_WEB_PORT=8040 (Poznote)
     * @return Scan
     */
    public static function of(string $composePath, array $env = []): array
    {
        return self::fromServices(self::withEnv(self::services($composePath), $env));
    }

    /**
     * As {@see of()}, from an already-parsed compose array.
     *
     * @param array<string, mixed> $compose
     * @return Scan
     */
    public static function ofParsed(array $compose): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];

        return self::fromServices(array_filter($services, 'is_array'));
    }

    /**
     * Why {@see of()} chose its primary port, null when it offers none.
     *
     * @param array<string, string> $env
     * @return Choice|null
     */
    public static function choiceOf(string $composePath, array $env = []): ?array
    {
        return self::fromServices(self::withEnv(self::services($composePath), $env), true)['choice'] ?? null;
    }

    /**
     * @param array<array-key, array<string, mixed>> $services
     * @return Scan|array{all: list<int>, primary: int, choice: Choice, refused: list<Refusal>}
     */
    private static function fromServices(array $services, bool $withChoice = false): array
    {
        $scan = self::scan($services);
        $ports = self::sorted($scan['public'], $scan['ranks'], $scan['demoted']);
        // A port another service publishes legitimately is not refused, however
        // the datastore next to it declared it.
        $refused = array_values(array_filter(
            $scan['refused'],
            static fn (array $entry): bool => !in_array($entry['port'], $ports, true)
        ));

        // Filtering must never leave the stack with nothing to point at. A
        // service judged a datastore by its env alone is a guess --
        // zabbix/zabbix-web-nginx-mysql is the frontend, and its MYSQL_* env
        // names the database it connects to -- so when nothing else survives,
        // its ports are the site. So is a datastore's port number
        // on a service nothing else names a datastore (OpenCloud's 9200). An
        // image that is a datastore by name (postgres:16) is never rescued.
        if ($ports === [] && $scan['guessed'] !== []) {
            $ports = self::sorted($scan['guessed']);
            $refused = array_values(array_filter(
                $refused,
                static fn (array $entry): bool => !in_array($entry['port'], $ports, true)
            ));
        }

        if ($ports === []) {
            return ['all' => [], 'refused' => $refused];
        }

        return $withChoice
            ? ['all' => $ports, 'primary' => $ports[0], 'choice' => self::choice($ports, $scan), 'refused' => $refused]
            : ['all' => $ports, 'primary' => $ports[0], 'refused' => $refused];
    }

    /**
     * Why $ports[0] leads: a preferred port, the port its service's own
     * healthcheck probes, or only the lowest number among ports its service
     * publishes on equal terms (Cabernet's 5004 stream over its 6077 web UI).
     * `alternatives` are those equal ports, which only a probe can tell apart.
     *
     * @param list<int> $ports
     * @param array{ranks: array<int, int>, demoted: list<int>, checked: list<int>, owners: array<int, list<string>>, published: array<string, list<int>>} $scan
     * @return Choice
     */
    private static function choice(array $ports, array $scan): array
    {
        $primary = $ports[0];
        $rank = static fn (int $port): int => $scan['ranks'][$port] ?? self::rankOf($port);
        if ($rank($primary) < PHP_INT_MAX - 1) {
            return ['reason' => self::CHOSEN_PREFERRED, 'alternatives' => []];
        }
        if (in_array($primary, $scan['checked'], true)) {
            return ['reason' => self::CHOSEN_HEALTHCHECK, 'alternatives' => []];
        }

        $tier = static fn (int $port): array => [InternalPorts::nonWebOn($port) !== null, in_array($port, $scan['demoted'], true)];
        $alternatives = [];
        foreach ($scan['owners'][$primary] ?? [] as $service) {
            foreach ($scan['published'][$service] ?? [] as $port) {
                if ($port !== $primary && $rank($port) === $rank($primary) && $tier($port) === $tier($primary)) {
                    $alternatives[] = $port;
                }
            }
        }
        $alternatives = array_values(array_unique($alternatives));

        return $alternatives === []
            ? ['reason' => self::CHOSEN_SINGLE, 'alternatives' => []]
            : ['reason' => self::CHOSEN_LOWEST, 'alternatives' => $alternatives];
    }

    public static function primaryOf(string $composePath): int
    {
        return self::of($composePath)['primary'] ?? self::DEFAULT_PRIMARY;
    }

    /**
     * @return array<array-key, array<string, mixed>>
     */
    private static function services(string $composePath): array
    {
        if (!file_exists($composePath)) {
            return [];
        }

        // Through ComposeYaml, not Symfony directly: it carries the anchor
        // rescue, without which Baserow's published 80 and 443 are invisible.
        $parsed = ComposeYaml::parseFile($composePath);
        $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        return array_filter($services, 'is_array');
    }

    /**
     * Port entries with the variables `.env` sets substituted; the rest are
     * left for {@see EnvVarDefault}.
     *
     * @param array<array-key, array<string, mixed>> $services
     * @param array<string, string> $env
     * @return array<array-key, array<string, mixed>>
     */
    private static function withEnv(array $services, array $env): array
    {
        if ($env === []) {
            return $services;
        }
        foreach ($services as $name => $service) {
            foreach (['ports', 'expose'] as $key) {
                if (!is_array($service[$key] ?? null)) {
                    continue;
                }
                foreach ($service[$key] as $index => $entry) {
                    if (is_string($entry)) {
                        $services[$name][$key][$index] = self::interpolated($entry, $env);
                    } elseif (is_array($entry) && is_string($entry['published'] ?? null)) {
                        $services[$name][$key][$index]['published'] = self::interpolated($entry['published'], $env);
                    }
                }
            }
        }

        return $services;
    }

    /**
     * @param array<string, string> $env
     */
    private static function interpolated(string $value, array $env): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $m) use ($env): string {
                $name = ($m[4] ?? '') !== '' ? $m[4] : $m[1];
                if (!array_key_exists($name, $env)) {
                    return $m[0];
                }

                return ($m[2] ?? '') === ':-' && $env[$name] === '' ? (string) $m[3] : $env[$name];
            },
            $value
        );
    }

    /**
     * `demoted` holds the ports that rank after every other web port: those no
     * service publishes under `ports:`, only `expose:`s (Profilarr's parser
     * exposes 5000 next to the app's published 6868), and those offered only
     * by a service another publishing service depends_on (9Router publishes
     * 20128 and depends on headroom's 8787).
     *
     * @param array<array-key, array<string, mixed>> $services
     * @return array{public: list<int>, refused: list<Refusal>, guessed: list<int>, ranks: array<int, int>, demoted: list<int>, checked: list<int>, owners: array<int, list<string>>, published: array<string, list<int>>}
     */
    private static function scan(array $services): array
    {
        $fronted = self::frontedServices($services);
        $ports = [];
        $refused = [];
        $guessed = [];
        $ranks = [];
        $checked = [];
        $owners = [];
        $published = [];
        foreach ($services as $name => $service) {
            if (self::isOptIn($service)) {
                continue;
            }
            $found = self::serviceScan($service);
            $isBehind = in_array(strtolower((string) $name), $fronted, true);
            $published[(string) $name] = $found['public'];
            foreach ($found['checked'] as $port) {
                $checked[$port] = true;
            }
            foreach ($found['public'] as $port) {
                $ports[$port] = ($ports[$port] ?? true) && ($isBehind || in_array($port, $found['demoted'], true));
                $ranks[$port] = min($ranks[$port] ?? PHP_INT_MAX, $found['ranks'][$port]);
                $owners[$port][] = (string) $name;
            }
            foreach ($found['refused'] as $entry) {
                $refused[$entry['port']] ??= $entry;
            }
            foreach ($found['guessed'] as $port) {
                $guessed[$port] = true;
            }
        }

        return [
            'public' => array_keys($ports),
            'refused' => array_values($refused),
            'guessed' => array_keys($guessed),
            'ranks' => $ranks,
            'demoted' => array_keys(array_filter($ports)),
            'checked' => array_keys($checked),
            'owners' => $owners,
            'published' => $published,
        ];
    }

    /**
     * Services a service that publishes ports `depends_on`, lowercased.
     *
     * @param array<array-key, array<string, mixed>> $services
     * @return list<string>
     */
    private static function frontedServices(array $services): array
    {
        $names = [];
        foreach ($services as $service) {
            if (!empty($service['ports'])) {
                array_push($names, ...ComposeService::of($service)->dependencyNames());
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Behind a `profiles:` gate: `docker compose up` without `--profile` never
     * starts it, so its ports serve nothing (Telegram Files' qBittorrent 8080).
     *
     * @param array<string, mixed> $service
     */
    private static function isOptIn(array $service): bool
    {
        $profiles = $service['profiles'] ?? null;

        return is_array($profiles) ? $profiles !== [] : is_string($profiles) && trim($profiles) !== '';
    }

    /**
     * `expose:` is lower priority than an explicit publish, so it is read
     * second and only adds ports the mappings did not already give.
     *
     * A filtered binding is reported rather than dropped: "the compose file
     * offers no port" and "it offers MySQL's, which we will never proxy" are
     * different answers, and only one of them means the app is misconfigured.
     *
     * `guessed` holds the refused ports the image itself does not account for:
     * only the service's env made it read as a datastore.
     *
     * A published port ranks as well as either of its ends: Stepifi's
     * `3169:3000` is a web server on 3000. `demoted` holds the service's
     * expose-only ports and, when its own healthcheck names one of its ports,
     * its other ports (Stepifi's Bull Board 3001, which nothing serves).
     *
     * @param array<string, mixed> $service
     * @return array{public: list<int>, refused: list<Refusal>, guessed: list<int>, ranks: array<int, int>, demoted: list<int>, checked: list<int>}
     */
    private static function serviceScan(array $service): array
    {
        $checked = self::healthcheckPort($service);
        $ranks = [];
        $healthy = [];
        $ports = [];
        $exposed = [];
        $refused = [];
        $guessed = [];
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping === null) {
                    continue;
                }
                if (!InternalPorts::coversBinding($mapping, $service)) {
                    if ($key === 'expose' && !isset($ports[$mapping->hostPort])) {
                        $exposed[] = $mapping->hostPort;
                    }
                    $ports[$mapping->hostPort] = true;
                    $ranks[$mapping->hostPort] = min(
                        $ranks[$mapping->hostPort] ?? PHP_INT_MAX,
                        self::rankOf($mapping->hostPort),
                        $mapping->containerPort === null ? PHP_INT_MAX : self::rankOf($mapping->containerPort)
                    );
                    if ($checked !== null && ($mapping->containerPort ?? $mapping->hostPort) === $checked) {
                        $healthy[$mapping->hostPort] = true;
                    }
                    continue;
                }
                $refusal = self::refusal($mapping, $service);
                $refused[$mapping->hostPort] ??= $refusal;
                if (self::isGuess($refusal, $service, $key === 'ports')) {
                    $guessed[$mapping->hostPort] = true;
                }
            }
        }

        return [
            'public' => array_keys($ports),
            'refused' => array_values($refused),
            'guessed' => array_keys($guessed),
            'ranks' => $ranks,
            'demoted' => array_values(array_unique(array_merge(
                $exposed,
                $healthy === [] ? [] : array_diff(array_keys($ports), array_keys($healthy))
            ))),
            'checked' => array_keys($healthy),
        ];
    }

    /**
     * The container port a service's healthcheck probes over HTTP
     * (`curl -f http://localhost:3000/health`), when it names one.
     *
     * @param array<string, mixed> $service
     */
    private static function healthcheckPort(array $service): ?int
    {
        $test = is_array($service['healthcheck'] ?? null) ? ($service['healthcheck']['test'] ?? null) : null;
        if (is_array($test)) {
            $test = implode(' ', array_filter($test, 'is_scalar'));
        }
        if (!is_string($test)
            || preg_match('#(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]):(\d{1,5})\b#', $test, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * A refusal the service itself does not bear out: its env alone made the
     * image read as a datastore, or the port number alone is a datastore's
     * on a published binding while neither image nor env names one (OpenCloud
     * serves on 9200, Elasticsearch's port).
     *
     * @param Refusal $refusal
     * @param array<string, mixed> $service
     */
    private static function isGuess(array $refusal, array $service, bool $published): bool
    {
        if (self::imageIsDatastore($service)) {
            return false;
        }
        if ($refusal['reason'] === 'datastore_image') {
            return true;
        }

        return $published && !SidecarEngine::isKnownDatastore('', array_diff_key($service, ['ports' => true, 'expose' => true]));
    }

    /**
     * Whether the image alone -- no env, no ports -- names a datastore.
     *
     * @param array<string, mixed> $service
     */
    private static function imageIsDatastore(array $service): bool
    {
        $image = $service['image'] ?? null;
        if (!is_string($image) || $image === '') {
            return false;
        }

        return SidecarEngine::isKnownDatastore('', ['image' => $image]);
    }

    /**
     * Why {@see InternalPorts::coversBinding()} turned a binding down: the port
     * itself is a datastore's, or the image behind it is.
     *
     * @param array<string, mixed> $service
     * @return Refusal
     */
    private static function refusal(PortMapping $mapping, array $service): array
    {
        $label = InternalPorts::datastoreOn($mapping->hostPort)
            ?? ($mapping->containerPort === null ? null : InternalPorts::datastoreOn($mapping->containerPort));

        if ($label !== null) {
            return ['port' => $mapping->hostPort, 'reason' => 'datastore', 'service' => $label];
        }

        $image = is_string($service['image'] ?? null) ? $service['image'] : 'datastore image';

        return ['port' => $mapping->hostPort, 'reason' => 'datastore_image', 'service' => $image];
    }

    /**
     * @param mixed $declared
     * @return list<mixed>
     */
    private static function entries($declared): array
    {
        if ($declared === null || $declared === '' || $declared === []) {
            return [];
        }

        return is_array($declared) ? array_values($declared) : [$declared];
    }

    /**
     * Preferred ports first, in listed order; everything else numerically
     * after them. $ranks overrides a port's own rank; $demoted ports follow
     * every other web port.
     *
     * @param list<int> $ports
     * @param array<int, int> $ranks
     * @param list<int> $demoted
     * @return list<int>
     */
    private static function sorted(array $ports, array $ranks = [], array $demoted = []): array
    {
        usort($ports, static function (int $a, int $b) use ($ranks, $demoted): int {
            // A non-web port stays last whichever way it is offered.
            $tier = [InternalPorts::nonWebOn($a) !== null, in_array($a, $demoted, true)]
                <=> [InternalPorts::nonWebOn($b) !== null, in_array($b, $demoted, true)];
            if ($tier !== 0) {
                return $tier;
            }
            $rankA = $ranks[$a] ?? self::rankOf($a);
            $rankB = $ranks[$b] ?? self::rankOf($b);

            return $rankA === $rankB ? $a <=> $b : $rankA <=> $rankB;
        });

        return $ports;
    }

    /**
     * A non-web port (SMTP, SSH, epmd) sorts after every other: Haraka's
     * `EXPOSE 25` became a site's front door (engine#88). Kept rather than
     * dropped, so a stack that offers nothing else still has a port.
     */
    private static function rankOf(int $port): int
    {
        if (InternalPorts::nonWebOn($port) !== null) {
            return PHP_INT_MAX;
        }
        $index = array_search($port, self::PREFERRED, true);

        return $index === false ? PHP_INT_MAX - 1 : (int) $index;
    }
}
