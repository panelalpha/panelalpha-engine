<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Port\ComposePortScan;
use App\Models\User;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;

/**
 * The port a deploy found serving the site when routing had to guess between
 * ports one service publishes on equal terms (Cabernet: its web UI on 6077
 * over a stream on 5004), and what served behind those ports then. A rebuild
 * keeps the port while that is unchanged; otherwise the guess is made again.
 */
final class AnsweringPort
{
    /** In the project's details: the `port` that served a page and the `choice` it was found for. */
    public const DETAIL = 'app_port_proven';

    /**
     * The guess routing makes: the lowest of the ports one service publishes
     * on equal terms, and the others. Null when there is none to make: a
     * recipe names the port, or the scan had a reason for its port.
     *
     * @param array<string, string> $env
     * @return ?array{primary: int, alternatives: list<int>}
     */
    public static function choiceIn(string $composePath, array $env, ?int $recipePort): ?array
    {
        if ($recipePort !== null) {
            return null;
        }
        $choice = ComposePortScan::choiceOf($composePath, $env);
        $primary = ComposePortScan::of($composePath, $env)['primary'] ?? null;
        if ($choice === null || $choice['reason'] !== ComposePortScan::CHOSEN_LOWEST || $primary === null) {
            return null;
        }

        return ['primary' => (int) $primary, 'alternatives' => self::sorted($choice['alternatives'])];
    }

    /**
     * The project's guess now; null too when its files cannot be read.
     *
     * @return ?array{primary: int, alternatives: list<int>}
     */
    public static function choice(DindProject $project): ?array
    {
        try {
            return self::choiceIn(
                $project->userAppComposeFileForPorts(),
                $project->environment()->forPortDetection(),
                Networking::recipeComposePort($project->userModel())
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What serves behind the guessed ports: every service publishing one of
     * them, whole, as compose resolves it (container ports, image, command,
     * environment). Null when compose names none of them.
     *
     * @param array<string, mixed> $config `docker compose config --format json`
     * @param array{primary: int, alternatives: list<int>} $choice
     */
    public static function behind(array $config, array $choice): ?string
    {
        $ports = [$choice['primary'], ...$choice['alternatives']];
        $services = [];
        foreach (is_array($config['services'] ?? null) ? $config['services'] : [] as $name => $service) {
            foreach (is_array($service) && is_array($service['ports'] ?? null) ? $service['ports'] : [] as $port) {
                $published = is_array($port) ? (string) ($port['published'] ?? '') : '';
                if (ctype_digit($published) && in_array((int) $published, $ports, true)) {
                    $services[(string) $name] = $service;
                }
            }
        }

        return $services === [] ? null : sha1((string) json_encode(self::canonical($services)));
    }

    /**
     * The port the last deploy found serving for this same guess, with the
     * same services behind it; null otherwise. The record is used up either
     * way: only a deploy whose port serves a page again writes it back.
     *
     * @param ?array{primary: int, alternatives: list<int>} $choice
     * @param ?array<string, mixed> $config
     */
    public static function claim(User $user, ?array $choice, ?array $config): ?int
    {
        $proven = $user->getDetails()[self::DETAIL] ?? null;
        if ($proven === null) {
            return null;
        }
        self::save($user, null);
        $was = is_array($proven) && is_array($proven['choice'] ?? null) ? $proven['choice'] : [];
        $behind = $choice === null || $config === null ? null : self::behind($config, $choice);
        $port = $proven['port'] ?? null;
        if ($behind === null || ($was['behind'] ?? null) !== $behind || (int) ($was['primary'] ?? 0) !== $choice['primary']
            || self::sorted((array) ($was['alternatives'] ?? [])) !== $choice['alternatives']) {
            return null;
        }

        return is_int($port) && in_array($port, [$choice['primary'], ...$choice['alternatives']], true) ? $port : null;
    }

    /**
     * Where {@see AppLauncher::betterRoute()} sends a site guessed onto the
     * lowest port, from one probe per port.
     *
     * @param array{primary: int, alternatives: list<int>} $choice
     * @param list<array<string, mixed>> $results
     * @return array{port: int, reason: string}|null
     */
    public static function better(array $choice, array $results): ?array
    {
        return AppLauncher::betterRoute(
            ['reason' => ComposePortScan::CHOSEN_LOWEST, 'alternatives' => $choice['alternatives']],
            $choice['primary'],
            null,
            $choice['primary'],
            $results
        );
    }

    /**
     * After a deploy's health check, with the site moved if another port
     * served it better: the routed port when it served a page, the guess, and
     * what served behind it, for the next rebuild. Forgotten otherwise: an
     * answer without a page proves nothing about which port is the site.
     *
     * @param array<string, mixed> $report {@see AppHealth::report()}
     */
    public static function remember(DindProject $project, array $report): void
    {
        $user = $project->userModel();
        $routed = $user->getAppPort();
        $choice = self::choice($project);
        $proven = null;
        if ($choice !== null && $routed !== null && in_array($routed, [$choice['primary'], ...$choice['alternatives']], true)
            && self::servesAPage(self::codeOf($routed, $report))) {
            $config = ZeroDowntimeRedeploy::composeConfig($project);
            $behind = $config === null ? null : self::behind($config, $choice);
            $proven = $behind === null ? null : ['port' => $routed, 'choice' => $choice + ['behind' => $behind]];
        }
        self::save($user, $proven);
    }

    /** As {@see AppLauncher::betterRoute()} reads a probe: 2xx and 3xx serve a page. */
    public static function servesAPage(?int $code): bool
    {
        return $code !== null && $code >= 200 && $code < 400;
    }

    /** Only when it changes; a record that cannot be saved never fails a deploy. */
    private static function save(User $user, ?array $proven): void
    {
        if (($user->getDetails()[self::DETAIL] ?? null) === $proven) {
            return;
        }
        $user->setDetails([self::DETAIL => $proven]);
        try {
            $user->save();
        } catch (\Throwable) {
        }
    }

    /** @param array<string, mixed> $report */
    private static function codeOf(int $port, array $report): ?int
    {
        foreach ((array) ($report['ports'] ?? []) as $result) {
            if (is_array($result) && ($result['port'] ?? null) === $port) {
                return is_int($result['http_code'] ?? null) ? $result['http_code'] : null;
            }
        }

        return null;
    }

    /** Maps in key order, lists as they are: the same definition always hashes the same. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $ports
     * @return list<int>
     */
    private static function sorted(array $ports): array
    {
        $ports = array_values(array_unique(array_map('intval', $ports)));
        sort($ports);

        return $ports;
    }
}
