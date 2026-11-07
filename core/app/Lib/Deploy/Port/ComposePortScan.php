<?php

namespace App\Lib\Deploy\Port;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Sidecar\ComposeService;
use App\Lib\Deploy\Sidecar\SidecarEngine;

/**
 * The public ports a compose file offers, best first; a binding with a datastore's port at either end
 * is dropped and returned under `refused`, so "offers nothing" and "offers only a database" differ.
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
     * @param  array<string, string>  $env  the project's `.env`, which compose interpolates host ports with
     * @return Scan
     */
    public static function of(string $composePath, array $env = []): array
    {
        return self::fromServices(self::withEnv(self::services($composePath), $env));
    }

    /**
     * As {@see of()}, from an already-parsed compose array.
     *
     * @param  array<string, mixed>  $compose
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
     * @param  array<string, string>  $env
     * @return Choice|null
     */
    public static function choiceOf(string $composePath, array $env = []): ?array
    {
        return self::fromServices(self::withEnv(self::services($composePath), $env), true)['choice'] ?? null;
    }

    /**
     * A port another service publishes is not refused. When nothing survives, ports refused on a guess
     * (env alone, or a datastore's number) are the site; an image that is a datastore by name never is.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     * @return Scan|array{all: list<int>, primary: int, choice: Choice, refused: list<Refusal>}
     */
    private static function fromServices(array $services, bool $withChoice = false): array
    {
        $scan = self::scan($services);
        $ports = self::sorted($scan['public'], $scan['ranks'], $scan['demoted']);
        $refused = array_values(array_filter(
            $scan['refused'],
            static fn (array $entry): bool => ! in_array($entry['port'], $ports, true)
        ));

        if ($ports === [] && $scan['guessed'] !== []) {
            $ports = self::sorted($scan['guessed']);
            $refused = array_values(array_filter(
                $refused,
                static fn (array $entry): bool => ! in_array($entry['port'], $ports, true)
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
     * Why $ports[0] leads: preferred, its service's healthcheck port, or only the lowest of equal ports,
     * which are then the `alternatives` only a probe can tell apart.
     *
     * @param  list<int>  $ports
     * @param  array{ranks: array<int, int>, demoted: list<int>, checked: list<int>, owners: array<int, list<string>>, published: array<string, list<int>>}  $scan
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
     * Through ComposeYaml, not Symfony directly: it carries the anchor rescue.
     *
     * @return array<array-key, array<string, mixed>>
     */
    private static function services(string $composePath): array
    {
        if (! file_exists($composePath)) {
            return [];
        }

        $parsed = ComposeYaml::parseFile($composePath);
        $services = is_array($parsed) && is_array($parsed['services'] ?? null) ? $parsed['services'] : [];

        return array_filter($services, 'is_array');
    }

    /**
     * Port entries with the variables `.env` sets substituted; the rest are left for {@see EnvVarDefault}.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     * @param  array<string, string>  $env
     * @return array<array-key, array<string, mixed>>
     */
    private static function withEnv(array $services, array $env): array
    {
        if ($env === []) {
            return $services;
        }
        foreach ($services as $name => $service) {
            foreach (['ports', 'expose'] as $key) {
                if (! is_array($service[$key] ?? null)) {
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
     * @param  array<string, string>  $env
     */
    private static function interpolated(string $value, array $env): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $m) use ($env): string {
                $name = ($m[4] ?? '') !== '' ? $m[4] : $m[1];
                if (! array_key_exists($name, $env)) {
                    return $m[0];
                }

                return ($m[2] ?? '') === ':-' && $env[$name] === '' ? (string) $m[3] : $env[$name];
            },
            $value
        );
    }

    /**
     * `demoted` holds the ports ranked after every other web port: expose-only ones, and those
     * offered only by a service another publishing service depends_on.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     * @return array{public: list<int>, refused: list<Refusal>, guessed: list<int>, ranks: array<int, int>, demoted: list<int>, checked: list<int>, owners: array<int, list<string>>, published: array<string, list<int>>}
     */
    private static function scan(array $services): array
    {
        $fronted = self::frontedServices($services);
        $scan = ['ports' => [], 'refused' => [], 'guessed' => [], 'ranks' => [], 'checked' => [], 'owners' => [], 'published' => []];
        foreach ($services as $name => $service) {
            if (self::isOptIn($service)) {
                continue;
            }
            $found = self::serviceScan($service);
            $isBehind = in_array(strtolower((string) $name), $fronted, true);
            self::addServiceScan($scan, (string) $name, $found, $isBehind);
        }

        return [
            'public' => array_keys($scan['ports']),
            'refused' => array_values($scan['refused']),
            'guessed' => array_keys($scan['guessed']),
            'ranks' => $scan['ranks'],
            'demoted' => array_keys(array_filter($scan['ports'])),
            'checked' => array_keys($scan['checked']),
            'owners' => $scan['owners'],
            'published' => $scan['published'],
        ];
    }

    /**
     * @param  array{ports: array<int, bool>, refused: array<int, Refusal>, guessed: array<int, true>, ranks: array<int, int>, checked: array<int, true>, owners: array<int, list<string>>, published: array<string, list<int>>}  $scan
     * @param  array{public: list<int>, refused: list<Refusal>, guessed: list<int>, ranks: array<int, int>, demoted: list<int>, checked: list<int>}  $found
     */
    private static function addServiceScan(array &$scan, string $name, array $found, bool $isBehind): void
    {
        $scan['published'][$name] = $found['public'];
        foreach ($found['checked'] as $port) {
            $scan['checked'][$port] = true;
        }
        foreach ($found['public'] as $port) {
            $scan['ports'][$port] = ($scan['ports'][$port] ?? true) && ($isBehind || in_array($port, $found['demoted'], true));
            $scan['ranks'][$port] = min($scan['ranks'][$port] ?? PHP_INT_MAX, $found['ranks'][$port]);
            $scan['owners'][$port][] = $name;
        }
        foreach ($found['refused'] as $entry) {
            $scan['refused'][$entry['port']] ??= $entry;
        }
        foreach ($found['guessed'] as $port) {
            $scan['guessed'][$port] = true;
        }
    }

    /**
     * Services a service that publishes ports `depends_on`, lowercased.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     * @return list<string>
     */
    private static function frontedServices(array $services): array
    {
        $names = [];
        foreach ($services as $service) {
            if (! empty($service['ports'])) {
                array_push($names, ...ComposeService::of($service)->dependencyNames());
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Behind a `profiles:` gate: `docker compose up` without `--profile` never starts it.
     *
     * @param  array<string, mixed>  $service
     */
    private static function isOptIn(array $service): bool
    {
        $profiles = $service['profiles'] ?? null;

        return is_array($profiles) ? $profiles !== [] : is_string($profiles) && trim($profiles) !== '';
    }

    /**
     * `expose:` is read second and only adds ports the mappings did not give. A filtered binding is
     * reported, not dropped; `guessed` holds refusals only the service's env accounts for.
     *
     * @param  array<string, mixed>  $service
     * @return array{public: list<int>, refused: list<Refusal>, guessed: list<int>, ranks: array<int, int>, demoted: list<int>, checked: list<int>}
     */
    private static function serviceScan(array $service): array
    {
        $checked = self::healthcheckPort($service);
        $scan = ['ranks' => [], 'healthy' => [], 'ports' => [], 'exposed' => [], 'refused' => [], 'guessed' => []];
        foreach (['ports', 'expose'] as $key) {
            foreach (self::entries($service[$key] ?? null) as $entry) {
                $mapping = PortMapping::parse($entry);
                if ($mapping === null) {
                    continue;
                }
                if (! InternalPorts::coversBinding($mapping, $service)) {
                    self::addPublicBinding($scan, $mapping, $checked, $key);

                    continue;
                }
                self::addRefusedBinding($scan, $mapping, $service, $key);
            }
        }

        return [
            'public' => array_keys($scan['ports']),
            'refused' => array_values($scan['refused']),
            'guessed' => array_keys($scan['guessed']),
            'ranks' => $scan['ranks'],
            'demoted' => array_values(array_unique(array_merge(
                $scan['exposed'],
                $scan['healthy'] === [] ? [] : array_diff(array_keys($scan['ports']), array_keys($scan['healthy']))
            ))),
            'checked' => array_keys($scan['healthy']),
        ];
    }

    /**
     * A published port ranks as well as either of its ends; with a healthcheck port named,
     * the service's other ports are demoted.
     *
     * @param  array{ranks: array<int, int>, healthy: array<int, true>, ports: array<int, true>, exposed: list<int>, refused: array<int, Refusal>, guessed: array<int, true>}  $scan
     */
    private static function addPublicBinding(array &$scan, PortMapping $mapping, ?int $checked, string $key): void
    {
        if ($key === 'expose' && ! isset($scan['ports'][$mapping->hostPort])) {
            $scan['exposed'][] = $mapping->hostPort;
        }
        $scan['ports'][$mapping->hostPort] = true;
        $scan['ranks'][$mapping->hostPort] = min(
            $scan['ranks'][$mapping->hostPort] ?? PHP_INT_MAX,
            self::rankOf($mapping->hostPort),
            $mapping->containerPort === null ? PHP_INT_MAX : self::rankOf($mapping->containerPort)
        );
        if ($checked !== null && ($mapping->containerPort ?? $mapping->hostPort) === $checked) {
            $scan['healthy'][$mapping->hostPort] = true;
        }
    }

    /**
     * @param  array{ranks: array<int, int>, healthy: array<int, true>, ports: array<int, true>, exposed: list<int>, refused: array<int, Refusal>, guessed: array<int, true>}  $scan
     * @param  array<string, mixed>  $service
     */
    private static function addRefusedBinding(array &$scan, PortMapping $mapping, array $service, string $key): void
    {
        $refusal = self::refusal($mapping, $service);
        $scan['refused'][$mapping->hostPort] ??= $refusal;
        if (self::isGuess($refusal, $service, $key === 'ports')) {
            $scan['guessed'][$mapping->hostPort] = true;
        }
    }

    /**
     * The container port a service's healthcheck probes over HTTP, when it names one.
     *
     * @param  array<string, mixed>  $service
     */
    private static function healthcheckPort(array $service): ?int
    {
        $test = is_array($service['healthcheck'] ?? null) ? ($service['healthcheck']['test'] ?? null) : null;
        if (is_array($test)) {
            $test = implode(' ', array_filter($test, 'is_scalar'));
        }
        if (! is_string($test)
            || preg_match('#(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]):(\d{1,5})\b#', $test, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * A refusal the service does not bear out: its env alone made it a datastore, or only the published port's number did.
     *
     * @param  Refusal  $refusal
     * @param  array<string, mixed>  $service
     */
    private static function isGuess(array $refusal, array $service, bool $published): bool
    {
        if (self::imageIsDatastore($service)) {
            return false;
        }
        if ($refusal['reason'] === 'datastore_image') {
            return true;
        }

        return $published && ! SidecarEngine::isKnownDatastore('', array_diff_key($service, ['ports' => true, 'expose' => true]));
    }

    /**
     * Whether the image alone -- no env, no ports -- names a datastore.
     *
     * @param  array<string, mixed>  $service
     */
    private static function imageIsDatastore(array $service): bool
    {
        $image = $service['image'] ?? null;
        if (! is_string($image) || $image === '') {
            return false;
        }

        return SidecarEngine::isKnownDatastore('', ['image' => $image]);
    }

    /**
     * Why {@see InternalPorts::coversBinding()} turned a binding down: the port is a datastore's, or the image is.
     *
     * @param  array<string, mixed>  $service
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
     * @param  mixed  $declared
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
     * Preferred ports first, then numerically; $ranks overrides a port's rank, $demoted and non-web ports go last.
     *
     * @param  list<int>  $ports
     * @param  array<int, int>  $ranks
     * @param  list<int>  $demoted
     * @return list<int>
     */
    private static function sorted(array $ports, array $ranks = [], array $demoted = []): array
    {
        usort($ports, static function (int $a, int $b) use ($ranks, $demoted): int {
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

    /** A non-web port (SMTP, SSH, epmd) sorts after every other, but is kept for a stack with nothing else. */
    private static function rankOf(int $port): int
    {
        if (InternalPorts::nonWebOn($port) !== null) {
            return PHP_INT_MAX;
        }
        $index = array_search($port, self::PREFERRED, true);

        return $index === false ? PHP_INT_MAX - 1 : (int) $index;
    }
}
