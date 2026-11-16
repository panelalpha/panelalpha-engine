<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * One engine setting that `pae configure` and the API change alike: a row of the settings table, named after the
 * `.env-core` key in lower case, wins over that key, which wins over the engine's default.
 *
 * Core reads it when it uses it, so a change reaches the next request without a restart and without root: php-fpm
 * and the queue may write the database, but not `.env-core`. A queue worker keeps the settings it read until it
 * restarts, after at most 20 jobs or an hour.
 */
final class EngineSetting
{
    public const SETTING = 'setting';

    public const ENV = 'env';

    public const DEFAULT = 'default';

    /**
     * @param  string  $env  the `.env-core` key
     * @param  string  $config  the config key the engine booted that key into, default included
     */
    public function __construct(public readonly string $env, public readonly string $config) {}

    /** The settings table's name for it. */
    public function name(): string
    {
        return strtolower($this->env);
    }

    /** The settings table's value, null when it has none or cannot be read. */
    public function stored(): ?string
    {
        try {
            $value = Setting::get($this->name());
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /** The settings table's value, else what `.env-core` or the engine's default gave at boot. */
    public function value(): mixed
    {
        return $this->stored() ?? config($this->config);
    }

    /** Where value() came from: {@see self::SETTING}, {@see self::ENV} or {@see self::DEFAULT}. */
    public function source(?EnvFile $env = null): string
    {
        if ($this->stored() !== null) {
            return self::SETTING;
        }

        return trim($this->written($env ?? EnvFile::current()) ?? '') === '' ? self::DEFAULT : self::ENV;
    }

    private function written(EnvFile $env): ?string
    {
        try {
            return $env->get($this->env);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stores $value; null removes the row, so `.env-core` or the default applies again.
     *
     * @return bool whether the stored value changed
     */
    public function set(?string $value): bool
    {
        if ($value === $this->stored()) {
            return false;
        }

        if ($value === null) {
            Setting::forget($this->name());
        } else {
            Setting::set($this->name(), $value);
        }

        return true;
    }
}
