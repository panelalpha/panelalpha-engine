<?php

namespace App\System\Privileged;

/** The directory core and the root broker share for files core hands it or has it write. */
final class Exchange
{
    public const DIRECTORY = '/var/lib/panelalpha/exchange';

    /** Read by pa-root-local too. */
    private const OVERRIDE = 'PA_EXCHANGE_DIR';

    public static function directory(): string
    {
        $override = getenv(self::OVERRIDE);

        return is_string($override) && $override !== '' ? rtrim($override, '/') : self::DIRECTORY;
    }

    /** A new empty file of core's own in it, named $prefix and a random suffix. */
    public static function file(string $prefix): string
    {
        $path = @tempnam(self::directory(), $prefix);
        if (! is_string($path) || dirname($path) !== self::root()) {
            self::discard($path);
            throw new \RuntimeException('Could not create a file in '.self::directory().'.');
        }

        return $path;
    }

    /** A new file of core's own in it holding $contents whole; refused, and removed, when they could not be written. */
    public static function fileHolding(string $prefix, string $contents): string
    {
        $path = self::file($prefix);
        if (@file_put_contents($path, $contents) !== strlen($contents)) {
            self::discard($path);
            throw new \RuntimeException('Could not write a file in '.self::directory().'.');
        }

        return $path;
    }

    /** Whether $path is a file directly in it, as file() makes them. */
    public static function holds(string $path): bool
    {
        $real = realpath($path);

        return $real !== false && dirname($real) === self::root();
    }

    private static function root(): string
    {
        return realpath(self::directory()) ?: self::directory();
    }

    private static function discard(string|false $path): void
    {
        if (is_string($path)) {
            @unlink($path);
        }
    }
}
