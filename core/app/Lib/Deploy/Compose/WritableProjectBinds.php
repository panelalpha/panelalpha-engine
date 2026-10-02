<?php

namespace App\Lib\Deploy\Compose;

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
