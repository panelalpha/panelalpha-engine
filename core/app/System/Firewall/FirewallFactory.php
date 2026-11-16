<?php

namespace App\System\Firewall;

use App\Lib\Traits\MemoisesNamedFactories;
use App\System;
use App\System\Firewall\Ufw\UfwFirewall;

/**
 * Resolves the {@see Firewall} the engine manages; a provider is a registration plus `FIREWALL_PROVIDER`.
 * The host side follows the same setting: scripts/firewall.sh runs scripts/firewall/<provider>.sh.
 */
final class FirewallFactory
{
    use MemoisesNamedFactories;

    public const UFW = 'ufw';

    /** @var array<string, callable(): Firewall> */
    private static array $factories = [];

    /** @var array<string, Firewall> */
    private static array $instances = [];

    /**
     * Register a provider, or replace one; a cached instance is dropped.
     *
     * @param  callable(): Firewall  $factory
     */
    public static function register(string $name, callable $factory): void
    {
        self::remember($name, $factory);
    }

    /** The provider `FIREWALL_PROVIDER` names, or ufw. */
    public static function default(): Firewall
    {
        return self::make(self::configuredName());
    }

    public static function make(?string $name = null): Firewall
    {
        return self::built(
            self::normalize($name ?? self::UFW),
            static fn (string $name): FirewallException => new FirewallException(sprintf(
                'Unknown firewall provider "%s"; available: %s',
                $name,
                implode(', ', array_keys(self::$factories))
            ))
        );
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::registered();
    }

    /** Drop every registration and cached instance. Tests only. */
    public static function reset(): void
    {
        self::forgetAll();
    }

    public static function configuredName(): string
    {
        $configured = config('env.FIREWALL_PROVIDER');

        return is_string($configured) && trim($configured) !== '' ? self::normalize($configured) : self::UFW;
    }

    private static function bootDefaults(): void
    {
        if (! isset(self::$factories[self::UFW])) {
            self::$factories[self::UFW] = static fn (): Firewall => new UfwFirewall(new System);
        }
    }
}
