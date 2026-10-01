<?php

namespace App\Lib\Deploy\Compose;

/**
 * A service's `extends:` merged into it, so the keys Compose would pull in are
 * the ones the hardening reads. Left as an `extends:`, the extended service's
 * `privileged`, host mounts, `network_mode: host` and the rest were merged by
 * Compose at run time, after {@see ServiceHardener} had already run over the
 * service's own keys and found nothing to strip.
 *
 * Same-file `extends` resolves against the file's own services; `extends.file`
 * reads the named file, confined to the project root ({@see NestedCompose}),
 * with its relative paths rebased to mean the same from there. The extending
 * service's own keys win the merge.
 *
 * No Laravel dependencies -- unit-testable.
 */
final class ComposeExtends
{
    private const MAX_DEPTH = 5;

    /**
     * @param array<string, mixed> $compose paths already relative to the project root
     * @param callable(string): ?string $read project-relative path => contents, null when unreadable
     * @return array<string, mixed> the compose with every service's `extends` resolved and removed
     */
    public static function resolve(array $compose, callable $read): array
    {
        if (!is_array($compose['services'] ?? null)) {
            return $compose;
        }

        foreach ($compose['services'] as $name => $service) {
            if (is_array($service)) {
                $compose['services'][$name] = self::resolveService($service, $compose['services'], $read, [], 0);
            }
        }

        return $compose;
    }

    /**
     * @param array<string, mixed> $service
     * @param array<array-key, mixed> $siblings the service map `extends` (same-file) resolves against
     * @param callable(string): ?string $read
     * @param list<string> $seen file:service keys already on the chain, to catch a cycle
     * @return array<string, mixed>
     */
    private static function resolveService(array $service, array $siblings, callable $read, array $seen, int $depth): array
    {
        $extends = $service['extends'] ?? null;
        unset($service['extends']);
        if ($extends === null) {
            return $service;
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new \InvalidArgumentException('The compose file nests extends: deeper than ' . self::MAX_DEPTH . ' levels.');
        }

        [$targetName, $file] = self::target($extends);
        if ($targetName === null) {
            return $service;
        }

        if ($file === null) {
            $key = ':' . $targetName;
            if (in_array($key, $seen, true) || !is_array($siblings[$targetName] ?? null)) {
                throw new \InvalidArgumentException("The compose file extends {$targetName}, which it does not define.");
            }
            $base = self::resolveService($siblings[$targetName], $siblings, $read, array_merge($seen, [$key]), $depth + 1);

            return self::merge($base, $service);
        }

        $relative = self::fromRoot($file);
        if (str_starts_with($relative, '/') || str_starts_with($relative, '..')) {
            throw new \InvalidArgumentException("The compose file extends {$file}, which is outside the project.");
        }
        $key = $relative . ':' . $targetName;
        if (in_array($key, $seen, true)) {
            throw new \InvalidArgumentException("The compose file extends {$file} in a cycle.");
        }
        $raw = $read($relative);
        $parsed = $raw === null ? null : ComposeYaml::parse($raw);
        if (!is_array($parsed) || !is_array($parsed['services'][$targetName] ?? null)) {
            throw new \InvalidArgumentException("The compose file extends {$targetName} from {$file}, which could not be read.");
        }
        $dir = dirname($relative) === '.' ? '' : dirname($relative);
        $rebased = NestedCompose::rebase(['services' => $parsed['services']], $dir)['services'];
        $base = self::resolveService($rebased[$targetName], $rebased, $read, array_merge($seen, [$key]), $depth + 1);

        return self::merge($base, $service);
    }

    /**
     * The extended service's name and the file it lives in (null = same file).
     *
     * @param mixed $extends
     * @return array{0: ?string, 1: ?string}
     */
    private static function target(mixed $extends): array
    {
        if (is_string($extends)) {
            return [trim($extends) === '' ? null : trim($extends), null];
        }
        if (!is_array($extends)) {
            return [null, null];
        }
        $service = is_string($extends['service'] ?? null) ? trim($extends['service']) : null;
        $file = is_string($extends['file'] ?? null) && trim($extends['file']) !== '' ? trim($extends['file']) : null;

        return [$service === '' ? null : $service, $file];
    }

    /**
     * Compose's `extends` merge: the extending service's keys win; lists are
     * appended (base first), maps are merged one level deeper the same way.
     * Every escaping key the base carries therefore lands in the service, where
     * {@see ServiceHardener} then removes it.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;
                continue;
            }
            $existing = $base[$key];
            if (is_array($existing) && is_array($value) && self::isList($existing) && self::isList($value)) {
                $base[$key] = array_values(array_merge($existing, $value));
            } elseif (is_array($existing) && is_array($value)) {
                $base[$key] = self::merge($existing, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @param array<array-key, mixed> $array */
    private static function isList(array $array): bool
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }

    /** `./docker/x.yml` -> `docker/x.yml`; `..`-leading when it leaves the project. */
    private static function fromRoot(string $path): string
    {
        $normalised = NestedCompose::path(trim($path), '');

        return $normalised === '.' ? '' : (string) preg_replace('#^\./#', '', $normalised);
    }
}
