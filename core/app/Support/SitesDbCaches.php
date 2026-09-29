<?php

namespace App\Support;

use App\System;
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

    public function __construct(private readonly System $system)
    {
    }

    /** @return array<string, string> key => what `.env` says, or the default */
    public function configured(): array
    {
        $env = $this->env();
        $values = [];
        foreach (self::CACHES as $key => $cache) {
            $values[$key] = $env->get($key) ?? $cache['default'];
        }

        return $values;
    }

    /**
     * What the running mariadbd uses, in MB, or null when sites-db is not running.
     *
     * @return ?array<string, int>
     */
    public function running(): ?array
    {
        $select = 'select ' . implode(', ', array_map(fn (array $c) => '@@' . $c['variable'], self::CACHES));
        try {
            $out = $this->system->exec([...$this->compose(), 'exec', '-T', self::SERVICE, 'sh', '-c',
                'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B -e "' . $select . '"'], [], 60);
        } catch (Throwable) {
            return null;
        }

        $bytes = preg_split('/\s+/', trim($out)) ?: [];
        if (count($bytes) !== count(self::CACHES) || array_filter($bytes, fn ($b) => !ctype_digit($b)) !== []) {
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
     * @param array<string, string> $values key => size
     * @return array{changed: array<int, string>, recreated: bool}
     */
    public function apply(array $values): array
    {
        $changed = $this->env()->set(array_map(fn (string $v) => strtoupper(trim($v)), $values));
        if ($changed === [] || $this->running() === null) {
            return ['changed' => $changed, 'recreated' => false];
        }

        $this->system->exec([...$this->compose(), 'up', '-d', '--no-deps', self::SERVICE], [], 300);

        return ['changed' => $changed, 'recreated' => true];
    }

    /** @return list<string> */
    private function compose(): array
    {
        return ['sudo', 'docker', 'compose', '--project-directory', $this->system->engineDirPath()];
    }

    private function env(): EnvFile
    {
        return new EnvFile($this->system->engineDirPath() . '/.env');
    }
}
