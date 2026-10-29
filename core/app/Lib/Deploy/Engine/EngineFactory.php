<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Deploy\Dind\DindEngine;

/**
 * Resolves the {@see ContainerEngine} the deploy pipeline should use.
 *
 * Adding an engine is a registration plus a config value, no deploy code:
 * `EngineFactory::register('podman', fn () => new PodmanEngine())` and
 * `DEPLOY_ENGINE=podman`. Instances are memoised per name.
 *
 * The one class in Deploy allowed to read Laravel config, and only to answer
 * "which engine is configured". Degrades to the default when unbooted.
 */
final class EngineFactory
{
    /** Per-account Docker-in-Docker on sysbox — the only engine that ships today. */
    public const DIND = 'dind';

    /** @var array<string, callable(): ContainerEngine> */
    private static array $factories = [];

    /** @var array<string, ContainerEngine> */
    private static array $instances = [];

    /**
     * Register an implementation, or replace one. Re-registering drops any
     * cached instance, so it takes effect immediately.
     *
     * @param  callable(): ContainerEngine  $factory
     */
    public static function register(string $name, callable $factory): void
    {
        $name = self::normalize($name);
        self::$factories[$name] = $factory;
        unset(self::$instances[$name]);
    }

    /**
     * The engine named by the `DEPLOY_ENGINE` setting, or {@see DIND}.
     */
    public static function default(): ContainerEngine
    {
        return self::make(self::configuredName());
    }

    /**
     * @throws UnknownEngineException when nothing is registered under $name
     */
    public static function make(?string $name = null): ContainerEngine
    {
        $name = self::normalize($name ?? self::DIND);
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        self::bootDefaults();
        $factory = self::$factories[$name] ?? null;
        if ($factory === null) {
            throw UnknownEngineException::forName($name, self::names());
        }

        return self::$instances[$name] = $factory();
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        self::bootDefaults();

        return array_keys(self::$factories);
    }

    public static function has(string $name): bool
    {
        self::bootDefaults();

        return isset(self::$factories[self::normalize($name)]);
    }

    /**
     * Drop every registration and cached instance. Tests only — an engine
     * registered by a test must not leak into the next one.
     */
    public static function reset(): void
    {
        self::$factories = [];
        self::$instances = [];
    }

    /**
     * What `DEPLOY_ENGINE` says, or {@see DIND} outside a booted application.
     */
    public static function configuredName(): string
    {
        if (! function_exists('config')) {
            return self::DIND;
        }
        try {
            $configured = config('env.DEPLOY_ENGINE');
        } catch (\Throwable $e) {
            return self::DIND;
        }

        return is_string($configured) && trim($configured) !== ''
            ? self::normalize($configured)
            : self::DIND;
    }

    private static function bootDefaults(): void
    {
        if (! isset(self::$factories[self::DIND])) {
            self::$factories[self::DIND] = static fn (): ContainerEngine => new DindEngine;
        }
    }

    private static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }
}
