<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\Port\EnvVarDefault;

/**
 * Checkout paths a service bind-mounts read-write, and the user it runs as.
 *
 * The checkout belongs to the account's uid; an image that drops to its own
 * user (stringer's uid 1000) cannot write a file it is handed, though it works
 * on a workstation where the developer happens to be uid 1000 too.
 *
 * No Laravel dependencies — unit-testable.
 */
final class WritableProjectBinds
{
    /**
     * @param array<string, mixed> $compose the run file, whose relative paths are relative to $projectDir
     * @return array<string, array{image: ?string, user: ?string, sources: list<string>}> service => what it writes
     */
    public static function of(array $compose, string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $services = $compose['services'] ?? null;
        if (!is_array($services) || $projectDir === '') {
            return [];
        }

        $out = [];
        foreach ($services as $name => $service) {
            if (!is_array($service) || !is_array($service['volumes'] ?? null)) {
                continue;
            }
            $sources = [];
            foreach ($service['volumes'] as $volume) {
                $source = self::writableSource($volume, $projectDir);
                if ($source !== null) {
                    $sources[$source] = true;
                }
            }
            if ($sources === []) {
                continue;
            }
            $image = is_string($service['image'] ?? null) && trim($service['image']) !== '' ? trim($service['image']) : null;
            $user = is_scalar($service['user'] ?? null) ? trim(EnvVarDefault::resolve((string) $service['user'])) : '';
            $user = $user === '' || str_contains($user, '$') ? null : $user;
            $out[(string) $name] = ['image' => $image, 'user' => $user, 'sources' => array_keys($sources)];
        }

        return $out;
    }

    /**
     * The uid a `user` spec runs as: `1000`, `1000:1000`, or a name looked up
     * in the image's passwd. Null for root or anything unresolvable.
     */
    public static function uidOf(string $user, ?string $passwd = null): ?int
    {
        $name = trim(explode(':', trim($user), 2)[0]);
        if ($name === '' || $name === 'root' || $name === '0') {
            return null;
        }
        if (ctype_digit($name)) {
            return (int) $name;
        }
        foreach (preg_split('/\R/', (string) $passwd) ?: [] as $line) {
            $fields = explode(':', $line);
            if (count($fields) >= 3 && $fields[0] === $name && ctype_digit($fields[2])) {
                return (int) $fields[2] === 0 ? null : (int) $fields[2];
            }
        }

        return null;
    }

    /**
     * Who an image built from $contents runs as, read before it is built: the
     * last literal `USER` of the final stage (following `FROM <stage>`), or,
     * when no stage sets one, the registry image the chain starts from, whose
     * own user it inherits. Both null when a `$` or a loop leaves it unknown.
     *
     * @return array{user: ?string, base: ?string}
     */
    public static function dockerfileUser(string $contents): array
    {
        $stages = [];
        $current = null;
        foreach (preg_split('/\R/', DockerfileFinder::withoutHeredocBodies($contents)) ?: [] as $line) {
            if (preg_match('/^\s*FROM\s+(?:--\S+\s+)*(\S+)(?:\s+AS\s+(\S+))?\s*$/i', $line, $m) === 1) {
                $stages[] = ['from' => $m[1], 'alias' => strtolower($m[2] ?? ''), 'user' => null];
                $current = count($stages) - 1;
            } elseif ($current !== null && preg_match('/^\s*USER\s+(\S+)\s*$/i', $line, $m) === 1) {
                $stages[$current]['user'] = $m[1];
            }
        }

        for ($i = count($stages) - 1, $hops = 0; $i >= 0 && $hops <= count($stages); $hops++) {
            $stage = $stages[$i];
            if ($stage['user'] !== null) {
                return ['user' => str_contains($stage['user'], '$') ? null : $stage['user'], 'base' => null];
            }
            $parent = null;
            foreach (array_slice($stages, 0, $i) as $j => $earlier) {
                if ($earlier['alias'] !== '' && $earlier['alias'] === strtolower($stage['from'])) {
                    $parent = $j;
                }
            }
            if ($parent === null) {
                return ['user' => null, 'base' => str_contains($stage['from'], '$') ? null : $stage['from']];
            }
            $i = $parent;
        }

        return ['user' => null, 'base' => null];
    }

    /**
     * An absolute path strictly inside the checkout, for a read-write bind.
     * The checkout root itself is never handed over: it holds .git and the
     * engine's own files.
     *
     * @param mixed $volume
     */
    private static function writableSource($volume, string $projectDir): ?string
    {
        if (is_string($volume)) {
            $parts = explode(':', EnvVarDefault::resolve($volume));
            if (count($parts) < 2) {
                return null;
            }
            $mode = count($parts) >= 3 ? $parts[count($parts) - 1] : '';
            if (in_array('ro', explode(',', $mode), true)) {
                return null;
            }
            $source = $parts[0];
        } elseif (is_array($volume)) {
            if (strtolower((string) ($volume['type'] ?? '')) !== 'bind' || !empty($volume['read_only'])) {
                return null;
            }
            $source = EnvVarDefault::resolve((string) ($volume['source'] ?? ''));
        } else {
            return null;
        }

        $source = trim($source);
        if ($source === '' || str_contains($source, '$')) {
            return null;
        }
        if (str_starts_with($source, './') || str_starts_with($source, '../') || $source === '.') {
            $source = $projectDir . '/' . $source;
        } elseif (!str_starts_with($source, '/')) {
            // A named volume.
            return null;
        }
        $source = self::normalised($source);
        if ($source === null || !str_starts_with($source, $projectDir . '/')) {
            return null;
        }

        return $source;
    }

    private static function normalised(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
