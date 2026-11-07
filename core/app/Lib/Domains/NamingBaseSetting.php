<?php

namespace App\Lib\Domains;

use App\Jobs\RebuildDomainConfigs;
use App\Models\Setting;

/** The settings projects are named under, which every vhost's cookie rules list as shared zones. */
final class NamingBaseSetting
{
    /** @var list<string> */
    public const array NAMES = ['default_wildcard_domain', 'cert_domain'];

    public static function covers(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /** Stores $value, an empty one removing the setting; a change re-renders every vhost. */
    public static function save(string $name, string $value): void
    {
        if ($value === (string) Setting::get($name)) {
            return;
        }

        if ($value === '') {
            Setting::forget($name);
        } else {
            Setting::set($name, $value);
        }

        RebuildDomainConfigs::dispatch();
    }
}
