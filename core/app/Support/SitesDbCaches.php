<?php

namespace App\Support;

use App\System;
use App\System\Privileged;
use App\System\Privileged\RootEngineCompose;
use Throwable;

/**
 * sites-db's MariaDB cache sizes: three keys in the engine's compose `.env`,
 * passed to mariadbd as flags by docker-compose.yml. MariaDB's own defaults are
 * 128 MB each; the engine starts it smaller. A change needs sites-db recreated,
 * which restarts the database under every hosted site.
 */
final class SitesDbCaches
{
    public const SERVICE = 'sites-db';

    /** @var array<string, array{label: string, default: string, variable: string, min_mb: int}> */
    public const CACHES = [
        'SITES_DB_INNODB_BUFFER_POOL_SIZE' => [
            'label' => 'InnoDB buffer pool', 'default' => '32M', 'variable' => 'innodb_buffer_pool_size', 'min_mb' => 8,
        ],
        'SITES_DB_KEY_BUFFER_SIZE' => [
            'label' => 'MyISAM key buffer', 'default' => '8M', 'variable' => 'key_buffer_size', 'min_mb' => 1,
        ],
        'SITES_DB_ARIA_PAGECACHE_BUFFER_SIZE' => [
            'label' => 'Aria page cache', 'default' => '8M', 'variable' => 'aria_pagecache_buffer_size', 'min_mb' => 1,
        ],
    ];

    public function __construct(private readonly System $system) {}

    /** @return array<string, string> key => what `.env` says, or the default */
    public function configured(): array
    {
        $written = $this->written();
        $values = [];
        foreach (self::CACHES as $key => $cache) {
            $values[$key] = $written[$key] ?? $cache['default'];
        }

        return $values;
    }

    /**
     * Each cache by its MariaDB variable, as the API reports it: the size set or the default, where it came from,
     * and what the running mariadbd uses in MB (null when sites-db is not running).
     *
     * @return array<string, array{value: string, source: string, default: string, running_mb: ?int}>
     */
    public function toArray(): array
    {
        $written = $this->written();
        $running = $this->running();
        $caches = [];
        foreach (self::CACHES as $key => $cache) {
            $caches[$cache['variable']] = [
                'value' => $written[$key] ?? $cache['default'],
                'source' => $written[$key] === null ? EngineSetting::DEFAULT : EngineSetting::ENV,
                'default' => $cache['default'],
                'running_mb' => $running[$key] ?? null,
            ];
        }

        return $caches;
    }

    /**
     * What the running mariadbd uses, in MB, or null when sites-db is not running.
     *
     * @return ?array<string, int>
     */
    public function running(): ?array
    {
        try {
            $out = $this->system->exec($this->compose()->sitesDbCacheSizes(), [], 60);
        } catch (Throwable) {
            return null;
        }

        $bytes = preg_split('/\s+/', trim($out)) ?: [];
        if (count($bytes) !== count(self::CACHES) || array_filter($bytes, fn ($b) => ! ctype_digit($b)) !== []) {
            return null;
        }

        return array_combine(array_keys(self::CACHES), array_map(fn ($b) => intdiv((int) $b, 1048576), $bytes));
    }

    /** Why $value cannot be the size of cache $key, or null when it can. */
    public static function badSize(string $key, string $value, int $hostMb): ?string
    {
        $mb = self::megabytes($value);
        if ($mb === null) {
            return 'A size with a unit, like 32M or 1G.';
        }
        $min = self::CACHES[$key]['min_mb'];
        if ($mb < $min) {
            return "At least {$min}M.";
        }
        if ($hostMb > 0 && $mb >= $hostMb) {
            return "Less than the server's {$hostMb} MB of RAM.";
        }

        return null;
    }

    /** "32M" => 32, "1G" => 1024, "512K" => 0; null when it is not a size. */
    public static function megabytes(string $value): ?int
    {
        if (preg_match('/^(\d+)([KMG])$/i', trim($value), $m) !== 1) {
            return null;
        }

        return match (strtoupper($m[2])) {
            'K' => intdiv((int) $m[1], 1024),
            'M' => (int) $m[1],
            default => (int) $m[1] * 1024,
        };
    }

    /**
     * Write the sizes, then recreate sites-db when it is running so they take
     * effect. One that is not running reads them at its next start.
     *
     * @param  array<string, string>  $values  key => size
     * @return array{changed: array<int, string>, recreated: bool}
     */
    public function apply(array $values): array
    {
        $changed = $this->env()->set(array_map(fn (string $v) => strtoupper(trim($v)), $values));
        if ($changed === [] || $this->running() === null) {
            return ['changed' => $changed, 'recreated' => false];
        }

        $this->system->exec($this->compose()->upWithoutDependencies(self::SERVICE), [], 300);

        return ['changed' => $changed, 'recreated' => true];
    }

    private function compose(): RootEngineCompose
    {
        return Privileged::engineCompose($this->system->engineDirPath());
    }

    /**
     * Sets the given sizes and recreates sites-db as {@see apply()} does; null puts a size back to the engine's
     * default, which compose reads from an empty value.
     *
     * @param  array<string, ?string>  $sizes  key => size, or null
     * @return array{changed: array<int, string>, recreated: bool}
     */
    public function set(array $sizes): array
    {
        return $this->apply(array_map(static fn (?string $size): string => $size ?? '', $sizes));
    }

    /** @return array<string, ?string> key => what `.env` sets it to, null when nothing or empty */
    private function written(): array
    {
        return array_map(
            static fn (?string $value): ?string => $value === null || $value === '' ? null : $value,
            $this->env()->values(array_keys(self::CACHES))
        );
    }

    private function env(): EngineEnvFile
    {
        return EngineEnvFile::compose($this->system);
    }
}
