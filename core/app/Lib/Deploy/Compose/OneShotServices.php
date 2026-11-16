<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Sidecar\ServiceRole;

/**
 * Services that provably run once and exit, which a restart policy would restart forever
 * (Chatwoot's `base` anchor, a migration job, a file-permission fixer).
 */
final class OneShotServices
{
    private const COMPLETED = 'service_completed_successfully';

    /** Commands that fix up files and return; a script of only these ends. */
    private const FINITE_COMMANDS = ['chown', 'chmod', 'chgrp', 'mkdir', 'cp', 'touch', 'ln', 'rm', 'install', 'echo', 'true'];

    /**
     * Something waits for it to finish and nothing waits for it to run, or nothing waits on it at all.
     *
     * @param  array<string, mixed>  $compose
     * @return list<string>
     */
    public static function in(array $compose): array
    {
        $services = array_filter(
            is_array($compose['services'] ?? null) ? $compose['services'] : [],
            'is_array'
        );
        $conditions = self::dependencyConditions($services);

        $oneShot = [];
        foreach ($services as $name => $service) {
            $name = (string) $name;
            $waitedOn = $conditions[$name] ?? [];
            $completes = $waitedOn !== [] && array_unique($waitedOn) === [self::COMPLETED];
            if ($completes
                || ($waitedOn === [] && (self::isBaseService($name, $service, $services)
                    || self::isJob($name, $service)
                    || self::runsOnlyFileSetup($service)))) {
                $oneShot[] = $name;
            }
        }

        return $oneShot;
    }

    /**
     * A base other services run and which runs nothing itself (it builds their image, or is their YAML
     * anchor), so it runs the image default and exits.
     *
     * @param  array<string, mixed>  $service
     * @param  array<array-key, array<string, mixed>>  $services
     */
    private static function isBaseService(string $name, array $service, array $services): bool
    {
        if (! is_string($service['image'] ?? null) || trim($service['image']) === '') {
            return false;
        }
        foreach (['ports', 'expose', 'command', 'entrypoint', 'healthcheck'] as $key) {
            if (! empty($service[$key])) {
                return false;
            }
        }

        return self::isRunBySibling($name, self::normalisedImage($service['image']), $services);
    }

    /**
     * A sibling must run this image without building it and declare a command, entrypoint or port;
     * an image-only pair would make each the other's base.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     */
    private static function isRunBySibling(string $name, string $image, array $services): bool
    {
        $usedElsewhere = false;
        foreach ($services as $other => $otherService) {
            if ((string) $other === $name || ! is_string($otherService['image'] ?? null)) {
                continue;
            }
            if (self::normalisedImage($otherService['image']) !== $image) {
                continue;
            }
            if (! empty($otherService['build'])) {
                return false;
            }
            if (self::runsItself($otherService)) {
                $usedElsewhere = true;
            }
        }

        return $usedElsewhere;
    }

    /**
     * A job nothing waits on, not the application (`./manage.py migrate` and exit): a known job name,
     * no published port and no healthcheck.
     *
     * @param  array<string, mixed>  $service
     */
    private static function isJob(string $name, array $service): bool
    {
        return ServiceRole::isJobService($name, $service);
    }

    /**
     * A command made only of file-setup steps (`chown 9999:9999 -R /media`), with no port or healthcheck.
     *
     * @param  array<string, mixed>  $service
     */
    private static function runsOnlyFileSetup(array $service): bool
    {
        foreach (['ports', 'expose', 'healthcheck'] as $key) {
            if (! empty($service[$key])) {
                return false;
            }
        }
        $command = trim(ComposeCommand::asString($service['entrypoint'] ?? null).' '
            .ComposeCommand::asString($service['command'] ?? null));
        $command = (string) preg_replace('#^(/bin/|/usr/bin/)?(ba)?sh\s+-c\s+#', '', $command);
        $command = trim($command, " \t\n\"'");
        if ($command === '') {
            return false;
        }
        foreach (preg_split('/&&?|\|\|?|;|\n/', $command) ?: [] as $step) {
            $word = basename(strtok(trim($step), " \t") ?: '');
            if (trim($step) !== '' && ! in_array($word, self::FINITE_COMMANDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a service says how to run itself — a real service, not an anchor.
     *
     * @param  array<string, mixed>  $service
     */
    private static function runsItself(array $service): bool
    {
        foreach (['ports', 'expose', 'command', 'entrypoint'] as $key) {
            if (! empty($service[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every condition another service waits on each service with; list-form `depends_on`, `links`,
     * `volumes_from` and `network_mode: service:` all mean it is expected to be running.
     *
     * @param  array<array-key, array<string, mixed>>  $services
     * @return array<string, list<string>>
     */
    private static function dependencyConditions(array $services): array
    {
        $conditions = [];
        foreach ($services as $service) {
            $dependsOn = is_array($service['depends_on'] ?? null) ? $service['depends_on'] : [];
            foreach ($dependsOn as $key => $value) {
                if (is_string($value)) {
                    $conditions[$value][] = 'service_started';
                } elseif (is_string($key)) {
                    $condition = is_array($value) ? ($value['condition'] ?? null) : null;
                    $conditions[$key][] = is_string($condition) ? $condition : 'service_started';
                }
            }
            foreach (['links', 'volumes_from'] as $key) {
                foreach (is_array($service[$key] ?? null) ? $service[$key] : [] as $reference) {
                    if (is_string($reference)) {
                        $conditions[explode(':', $reference)[0]][] = 'service_started';
                    }
                }
            }
            $networkMode = $service['network_mode'] ?? null;
            if (is_string($networkMode) && str_starts_with($networkMode, 'service:')) {
                $conditions[substr($networkMode, 8)][] = 'service_started';
            }
        }

        return $conditions;
    }

    /** `chatwoot` and `chatwoot:latest` name the same image. */
    private static function normalisedImage(string $image): string
    {
        $image = trim($image);
        $lastSegment = substr((string) strrchr('/'.$image, '/'), 1);

        return str_contains($lastSegment, ':') || str_contains($image, '@') ? $image : $image.':latest';
    }
}
