<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A stable, non-reversible id for one installation, hashed from host facts that never leave the box. Pinned on the
 * host bind mount once derived, and the pin always wins: a new IP or more RAM is still the same installation.
 */
class InstallFingerprint
{
    /**
     * Host-side, survives container rebuilds and engine updates.
     */
    public const PIN_FILE = '/opt/panelalpha/.panelalpha-install-id';

    public const LENGTH = 32;

    /**
     * Facts that contribute to the id, in a fixed order. Anything absent
     * contributes an empty string rather than shifting the others along.
     *
     * @var list<string>
     */
    public const DERIVATION_KEYS = [
        'docker_id',
        'machine_id',
        'hostname',
        'cpu_model',
        'cpu_cores',
        'mem_total_kb',
        'public_ip',
    ];

    /**
     * Hash the machine facts into an id; '' when every one is missing, as every install that fails to probe
     * would otherwise share one hash and merge into one phantom installation.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function derive(array $facts): string
    {
        $material = [];
        foreach (self::DERIVATION_KEYS as $key) {
            $value = $facts[$key] ?? '';
            $material[] = is_scalar($value) ? trim((string) $value) : '';
        }

        if (trim(implode('', $material)) === '') {
            return '';
        }

        return substr(hash('sha256', 'panelalpha-install|'.implode('|', $material)), 0, self::LENGTH);
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{'.self::LENGTH.'}$/', $id) === 1;
    }

    /**
     * The pinned id, or null when nothing is pinned yet / the pin is corrupt.
     */
    public static function readPin(string $path = self::PIN_FILE): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw)) {
            return null;
        }
        $id = trim($raw);

        return self::isValidId($id) ? $id : null;
    }

    /**
     * Pin an id. Best-effort: an unwritable /opt/panelalpha means the id is
     * re-derived next time, which is survivable, so it must not throw.
     */
    public static function writePin(string $id, string $path = self::PIN_FILE): bool
    {
        if (! self::isValidId($id)) {
            return false;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            return false;
        }

        $tmp = $path.'.tmp.'.getmypid();
        if (@file_put_contents($tmp, $id."\n", LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
