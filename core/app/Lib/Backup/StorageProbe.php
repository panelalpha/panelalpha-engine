<?php

namespace App\Lib\Backup;

use App\Models\BackupContainer;
use RuntimeException;
use Throwable;

/**
 * Tests a container's storage. A failure answers on one line: the driver, host and location, then the storage's own
 * reason, with the container's secrets masked.
 */
final class StorageProbe
{
    /** A shorter secret is left as it is: it would match ordinary text such as an address or a port. */
    private const SHORTEST_MASKED = 8;

    private const MASK = '***';

    public static function check(BackupContainer $container): void
    {
        try {
            $container->storage()->test();
        } catch (Throwable $e) {
            throw new RuntimeException(self::answer($container, $e->getMessage()));
        }
    }

    private static function answer(BackupContainer $container, string $reason): string
    {
        $reason = trim($reason) !== '' ? $reason : 'Backup storage connection test failed.';
        $secrets = array_filter($container->maskedInMessages(), static fn (string $secret): bool => strlen($secret) >= self::SHORTEST_MASKED);

        return trim((string) preg_replace('/\s+/', ' ', str_replace($secrets, self::MASK, self::target($container).': '.$reason)));
    }

    /** `<driver> <host>[:<port>] <location>`, the host being an FTP or SFTP server or an S3 endpoint when one is set. */
    private static function target(BackupContainer $container): string
    {
        $credentials = $container->credentials ?? [];
        $host = $credentials['host'] ?? $credentials['endpoint'] ?? null;
        $port = $credentials['port'] ?? null;
        $address = is_string($host) && $host !== '' ? $host.(is_scalar($port) && $port !== '' ? ':'.$port : '') : null;

        return implode(' ', array_filter([$container->driver, $address, $container->location]));
    }
}
