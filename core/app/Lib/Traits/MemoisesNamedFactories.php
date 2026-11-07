<?php

namespace App\Lib\Traits;

use Throwable;

/**
 * Factories registered by name, each product built once per name. The using class
 * declares the static `$factories` and `$instances` arrays and `bootDefaults()`.
 */
trait MemoisesNamedFactories
{
    abstract private static function bootDefaults(): void;

    private static function remember(string $name, callable $factory): void
    {
        $name = self::normalize($name);
        self::$factories[$name] = $factory;
        unset(self::$instances[$name]);
    }

    /** @param  callable(string): Throwable  $unknown  the error for a name nothing is registered under */
    private static function built(string $name, callable $unknown): mixed
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        self::bootDefaults();
        $factory = self::$factories[$name] ?? null;
        if ($factory === null) {
            throw $unknown($name);
        }

        return self::$instances[$name] = $factory();
    }

    /** @return list<string> */
    private static function registered(): array
    {
        self::bootDefaults();

        return array_keys(self::$factories);
    }

    private static function forgetAll(): void
    {
        self::$factories = [];
        self::$instances = [];
    }

    private static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }
}
