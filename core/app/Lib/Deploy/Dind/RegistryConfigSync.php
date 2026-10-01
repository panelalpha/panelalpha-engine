<?php

namespace App\Lib\Deploy\Dind;

/**
 * Deciding what a registry-settings refresh does to a *running* account,
 * from what the host already asked it for a stream, not into one.
 *
 * Laravel-free and read-only: parses `docker inspect`/`docker top` output,
 * decides nothing about the account itself. {@see \App\System\Project\Dind\Inner\ImageSeeding::ensureRegistryConfig()}
 * is the only caller, and is what runs the commands and the host signal.
 */
final class RegistryConfigSync
{
    public const DAEMON_JSON_PATH = '/etc/docker/daemon.json';

    /**
     * Whether the account's container already binds daemon.json from the
     * host, from `docker inspect --format '{{json .Mounts}}'`'s own output.
     *
     * An account created before the mount shipped has no `Destination`
     * matching it at all; one created after does, whatever path renders it
     * from on the host. That difference is the whole decision: a mounted
     * account can be told about a new registry by rewriting the host file and
     * signalling it, an unmounted one cannot be reached without a shell
     * inside it, so nothing here may try.
     */
    public static function hasDaemonJsonMount(string $inspectedMountsJson): bool
    {
        $mounts = json_decode(trim($inspectedMountsJson), true);
        if (!is_array($mounts)) {
            return false;
        }
        foreach ($mounts as $mount) {
            if (is_array($mount) && ($mount['Destination'] ?? null) === self::DAEMON_JSON_PATH) {
                return true;
            }
        }

        return false;
    }

    /**
     * The host-visible PID of dockerd in `docker top <container> -eo
     * pid,comm`'s own output, or null when it is not running or not found.
     *
     * The account is its own PID namespace, so this is the only PID a signal
     * sent from the host (not a shell inside the account) can reach.
     */
    public static function dockerdHostPid(string $topOutput): ?int
    {
        foreach (preg_split('/\r?\n/', trim($topOutput)) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+dockerd\s*$/', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
