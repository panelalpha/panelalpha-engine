<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * A stable, non-reversible identity for one engine installation.
 *
 * There is no account to log in to and no key to carry: the id is derived from
 * facts about the machine the engine runs on — the Docker daemon's own id, the
 * CPU, the amount of RAM, the host name and the public IP. None of those facts
 * ever leave the box; only the hash does, and a hash of a handful of host facts
 * cannot be turned back into them.
 *
 * Once derived, the id is *pinned* to a file under /opt/panelalpha, and the
 * pinned value always wins. That matters more than it looks: a machine that
 * gets a new IP, more RAM or a rebuilt Docker daemon is still the same
 * installation, and an id that changed underneath it would split that
 * installation's failure history in two and quietly double-count every
 * recurring problem. Derivation is how the id is *born*, not how it is kept.
 *
 * The pin file is deliberately outside the core container's own storage: it
 * sits on the host bind mount, so it survives an image rebuild, an engine
 * update, and `docker compose down -v`.
 *
 * The derivation itself is pure — {@see derive()} is unit-testable.
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
     * Hash the machine facts into an id.
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

        // Refuse to mint an id out of nothing: if every anchor is missing we
        // would hand the same hash to every install that ever fails to probe,
        // and they would all merge into one phantom installation.
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
