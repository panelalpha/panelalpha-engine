<?php

namespace App\System\Firewall;

use App\System;
use App\System\Firewall\Ufw\UfwFirewall;

/**
 * Resolves the {@see Firewall} the engine manages.
 *
 * Adding a provider is a registration plus a setting, no controller or tool
 * changes: `FirewallFactory::register('firewalld', fn () => new Firewalld(...))`
 * and `FIREWALL_PROVIDER=firewalld`. The host side follows the same setting:
 * scripts/firewall.sh runs scripts/firewall/<provider>.sh.
 */
final class FirewallFactory
{
    public const UFW = 'ufw';

    /** @var array<string, callable(): Firewall> */
    private static array $factories = [];

    /** @var array<string, Firewall> */
    private static array $instances = [];

    /**
     * Register a provider, or replace one; a cached instance is dropped.
     *
     * @param callable(): Firewall $factory
     */
    public static function register(string $name, callable $factory): void
    {
        $name = self::normalize($name);
        self::$factories[$name] = $factory;
        unset(self::$instances[$name]);
    }

    /** The provider `FIREWALL_PROVIDER` names, or ufw. */
    public static function default(): Firewall
    {
        return self::make(self::configuredName());
    }

    public static function make(?string $name = null): Firewall
    {
        $name = self::normalize($name ?? self::UFW);
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        self::bootDefaults();
        $factory = self::$factories[$name] ?? null;
        if ($factory === null) {
            throw new FirewallException(sprintf(
                'Unknown firewall provider "%s"; available: %s',
                $name,
                implode(', ', array_keys(self::$factories))
            ));
        }

        return self::$instances[$name] = $factory();
    }

    /** @return list<string> */
    public static function names(): array
    {
        self::bootDefaults();

        return array_keys(self::$factories);
    }

    /** Drop every registration and cached instance. Tests only. */
    public static function reset(): void
    {
        self::$factories = [];
        self::$instances = [];
    }

    public static function configuredName(): string
    {
        $configured = config('env.FIREWALL_PROVIDER');

        return is_string($configured) && trim($configured) !== '' ? self::normalize($configured) : self::UFW;
    }

    private static function bootDefaults(): void
    {
        if (!isset(self::$factories[self::UFW])) {
            self::$factories[self::UFW] = static fn (): Firewall => new UfwFirewall(new System());
        }
    }

    private static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }
}
