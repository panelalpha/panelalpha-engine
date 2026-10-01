<?php

namespace App\Lib\Deploy\Port;

/**
 * One entry of a Compose `ports:` or `expose:` list, as the host port it
 * publishes and the container port behind it.
 *
 * Compose accepts every one of these:
 *
 *     8080                       "8080:8080"        "8080:80/tcp"
 *     "127.0.0.1:8080:8080"      "0.0.0.0:8080:80"  "${APP_PORT:-8090}:8000"
 *
 * A binding to loopback publishes nothing anyone outside can reach, so it is
 * not a mapping at all.
 */
final class PortMapping
{
    /** @var list<string> */
    private const LOOPBACK_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    private function __construct(public readonly int $hostPort, public readonly ?int $containerPort)
    {
    }

    /**
     * @param mixed $mapping one entry as Compose wrote it
     */
    public static function parse($mapping): ?self
    {
        if (is_int($mapping)) {
            return $mapping > 0 ? new self($mapping, null) : null;
        }
        if (is_array($mapping)) {
            return self::fromLongSyntax($mapping);
        }

        return is_string($mapping) ? self::fromString($mapping) : null;
    }

    /**
     * `{target: 25000, published: "25000", host_ip: 127.0.0.1}`. Without
     * `published` it reads like a bare `25000`.
     *
     * @param array<array-key, mixed> $mapping
     */
    private static function fromLongSyntax(array $mapping): ?self
    {
        $hostIp = $mapping['host_ip'] ?? null;
        if (is_string($hostIp) && in_array(trim($hostIp, '[] '), self::LOOPBACK_HOSTS, true)) {
            return null;
        }
        $target = self::scalarPort($mapping['target'] ?? null);
        if ($target <= 0) {
            return null;
        }
        $published = $mapping['published'] ?? null;
        if ($published === null || $published === '') {
            return new self($target, null);
        }
        $hostPort = self::scalarPort($published);

        return $hostPort > 0 ? new self($hostPort, $target) : null;
    }

    /** A port given as int or string, env defaults resolved; a range reads as its first port. */
    private static function scalarPort(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) ? self::portOf(EnvVarDefault::resolve($value)) : 0;
    }

    private static function fromString(string $mapping): ?self
    {
        // Env-var defaults are resolved before the split, so
        // "${APP_PORT:-8090}:8000" becomes "8090:8000" rather than nonsense.
        $parts = explode(':', trim(EnvVarDefault::resolve($mapping)));
        if (self::isLoopbackBinding($parts)) {
            return null;
        }

        $containerPort = self::portOf((string) end($parts));
        $parts[count($parts) - 1] = self::withoutProtocol((string) end($parts));
        if (count($parts) === 3) {
            array_shift($parts);
        }
        $hostPort = self::portOf((string) array_shift($parts));

        return $hostPort > 0 ? new self($hostPort, $containerPort ?: null) : null;
    }

    /**
     * @param list<string> $parts
     */
    private static function isLoopbackBinding(array $parts): bool
    {
        return count($parts) >= 2 && in_array($parts[0], self::LOOPBACK_HOSTS, true);
    }

    private static function portOf(string $value): int
    {
        return (int) trim(self::withoutProtocol($value));
    }

    private static function withoutProtocol(string $value): string
    {
        return explode('/', $value)[0];
    }
}
