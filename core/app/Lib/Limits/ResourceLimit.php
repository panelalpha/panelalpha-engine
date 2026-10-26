<?php

namespace App\Lib\Limits;

/**
 * One of a project's resource limits: where it is stored, how it is spelled on
 * the command line, and how it reads back.
 *
 * The table below is the only place the set is written down. Every limit
 * stores UNLIMITED (-1) for "no limit" and null for "never set"; memory_limit
 * alone refuses -1, because every project runs with one.
 */
final class ResourceLimit
{
    /** "No limit", as a limit stores it and the API answers it; null means the limit was never set. */
    public const int UNLIMITED = -1;

    private function __construct(
        /** Key inside User::$details. */
        public readonly string $key,
        /** Console option, without the leading `--`. */
        public readonly string $option,
        /** Value placeholder shown in the "at least one is required" error. */
        public readonly string $argument,
        /** Appended when formatting a value; empty for a bare count. */
        public readonly string $unit,
        public readonly bool $isFloat,
        /** True for disk_space_limit alone: a new project that names none starts at UNLIMITED, not null. */
        public readonly bool $startsUnlimited,
        /** Whether changing it only takes effect after a rebuild. */
        public readonly bool $needsRebuild,
        /** True for memory_limit alone: every project has one, so it can be changed but not removed. */
        public readonly bool $alwaysApplies = false,
    ) {}

    /** @return list<self> */
    public static function all(): array
    {
        static $all = null;

        return $all ??= [
            new self('disk_space_limit', 'disk-space-limit', 'LIMIT_IN_MB', ' MB', false, true, true),
            new self('memory_limit', 'memory-limit', 'LIMIT_IN_MB', ' MB', false, false, true, true),
            new self('cpu_limit', 'cpu-limit', 'LIMIT_IN_CPUS', ' CPUs', true, false, true),
            new self('device_read_bps', 'device-read-bps', 'LIMIT_IN_BPS', ' bps', false, false, true),
            new self('device_write_bps', 'device-write-bps', 'LIMIT_IN_BPS', ' bps', false, false, true),
            new self('bandwidth_limit', 'bandwidth-limit', 'LIMIT_IN_MB', ' MB', false, false, false),
            new self('mysql_databases_limit', 'mysql-databases-limit', 'LIMIT', '', false, false, false),
            new self('ftp_accounts_limit', 'ftp-accounts-limit', 'LIMIT', '', false, false, false),
            new self('sftp_accounts_limit', 'sftp-accounts-limit', 'LIMIT', '', false, false, false),
            new self('addon_domains_limit', 'addon-domains-limit', 'LIMIT', '', false, false, false),
            new self('subdomains_limit', 'subdomains-limit', 'LIMIT', '', false, false, false),
            new self('inodes_limit', 'inodes-limit', 'LIMIT', '', false, false, true),
        ];
    }

    public static function isUnlimited(int|float|null $value): bool
    {
        return $value !== null && $value < 0;
    }

    /** The value to put on a container or a quota: null when the limit is unset or unlimited. */
    public static function enforced(int|float|null $value): int|float|null
    {
        return self::isUnlimited($value) ? null : $value;
    }

    /**
     * The count limit a project has reached, or null when it still has room.
     * An unset limit and UNLIMITED never cap a count.
     */
    public static function reached(?int $limit, int $count): ?int
    {
        if ($limit === null || self::isUnlimited($limit)) {
            return null;
        }

        return $limit <= $count ? $limit : null;
    }

    /** A stored value in another unit: UNLIMITED and null are answered as they are, never multiplied. */
    public static function scaled(int|float|null $value, int $factor): int|float|null
    {
        if ($value === null) {
            return null;
        }

        return self::isUnlimited($value) ? self::UNLIMITED : $value * $factor;
    }

    public static function byKey(string $key): self
    {
        static $byKey = null;
        $byKey ??= array_column(array_map(
            static fn (self $l): array => [$l->key, $l],
            self::all()
        ), 1, 0);

        return $byKey[$key] ?? throw new \InvalidArgumentException("Unknown resource limit `{$key}`.");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $l): string => $l->key, self::all());
    }

    /**
     * The stored value, cast, or null when unset.
     *
     * @param  array<string, mixed>  $details
     */
    public function read(array $details): int|float|null
    {
        if (! array_key_exists($this->key, $details) || $details[$this->key] === null) {
            return null;
        }

        return $this->isFloat ? (float) $details[$this->key] : (int) $details[$this->key];
    }

    /** A raw value as it is stored: any negative value is UNLIMITED. */
    public function normalize(string|int|float $raw): int|float
    {
        if ($this->isFloat) {
            $value = (float) $raw;

            return $value < 0 ? (float) self::UNLIMITED : $value;
        }

        return max(self::UNLIMITED, (int) $raw);
    }

    /** Why a raw console value cannot be stored, or null when it can. */
    public function rejectionReason(string|int|float $raw): ?string
    {
        if ($this->alwaysApplies && (float) $raw < 1) {
            $name = str_replace('-', ' ', $this->option);

            return 'The '.$name.($this->unit === '' ? '' : ' is in'.$this->unit)
                .' and must be a positive number.';
        }

        return null;
    }

    /** How a stored value reads back to an operator: unset and UNLIMITED both read "no limit". */
    public function format(int|float|null $value): string
    {
        if ($value === null || self::isUnlimited($value)) {
            return 'no limit';
        }

        return (string) $value.$this->unit;
    }
}
