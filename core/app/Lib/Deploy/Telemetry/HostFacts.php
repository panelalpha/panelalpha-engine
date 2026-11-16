<?php

namespace App\Lib\Deploy\Telemetry;

use App\Auth\TokenAbilities;
use App\Lib\Host\DataDisk;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;

/**
 * The machine facts telemetry needs: {@see InstallFingerprint} material (never sent) and envelope facts. Read through
 * `docker info` and /proc, which work from the `core` and `cron` containers alike; an unreadable fact is null.
 */
class HostFacts
{
    /** @var ?array<string, mixed> */
    private static ?array $dockerInfo = null;

    /**
     * Material for the install id. Not transmitted.
     *
     * @return array<string, mixed>
     */
    public static function forFingerprint(System $system): array
    {
        $info = self::dockerInfo($system);
        $cpu = self::cpu();

        return [
            'docker_id' => self::str($info['ID'] ?? null),
            'machine_id' => self::machineId(),
            'hostname' => self::str($info['Name'] ?? null) ?? gethostname(),
            'cpu_model' => $cpu['model'],
            'cpu_cores' => $cpu['cores'],
            'mem_total_kb' => self::memTotalKb(),
            'public_ip' => self::publicIp(),
        ];
    }

    /**
     * Platform facts sent once per batch. Nothing here identifies a customer:
     * no IP, no hostname, no domain, no account name.
     *
     * @return array<string, mixed>
     */
    public static function envelope(System $system): array
    {
        $info = self::dockerInfo($system);
        $cpu = self::cpu();
        $memKb = self::memTotalKb();

        return [
            'engine_version' => self::str(config('system.version')),
            'os' => self::str($info['OperatingSystem'] ?? null),
            'kernel' => self::str($info['KernelVersion'] ?? null),
            'docker' => self::str($info['ServerVersion'] ?? null),
            'runtimes' => self::runtimes($info),
            'php' => PHP_VERSION,
            'cpu_cores' => $cpu['cores'],
            'memory_gb' => $memKb === null ? null : (int) round($memKb / 1024 / 1024),
            'webserver' => self::webserver($system),
            'accounts' => self::accountCount(),
        ];
    }

    /**
     * host() for the caller of GET /system/info, or null for a project-scoped token: that is a tenant's,
     * and the machine's name, kernel and uptime are the operator's.
     *
     * @return ?array<string, mixed>
     */
    public static function hostFor(TokenAbilities $caller, System $system): ?array
    {
        return $caller->isProjectScoped() ? null : self::host($system);
    }

    /**
     * The machine as its own admin sees it, for GET /system/info. Unlike envelope() it names the host, by the
     * daemon's hostname, as core's own is the container's.
     *
     * @return array<string, mixed>
     */
    public static function host(System $system): array
    {
        $info = self::dockerInfo($system);
        $cpu = self::cpu();
        $memKb = self::memTotalKb();

        return [
            'hostname' => self::str($info['Name'] ?? null),
            'os' => self::str($info['OperatingSystem'] ?? null),
            'kernel' => self::str($info['KernelVersion'] ?? null),
            'uptime_seconds' => self::uptimeSeconds(),
            'cpu_cores' => $cpu['cores'],
            'memory_total_bytes' => $memKb === null ? null : $memKb * 1024,
            'disk_total_bytes' => DataDisk::usage()['total'] ?? null,
            'ipv4' => self::publicIp(),
        ];
    }

    /** /proc/uptime is not namespaced, so this is the host's. */
    private static function uptimeSeconds(): ?int
    {
        $raw = is_readable('/proc/uptime') ? (string) @file_get_contents('/proc/uptime') : '';

        return preg_match('/^(\d+)/', $raw, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * `docker info` as an array, probed once per process.
     *
     * @return array<string, mixed>
     */
    private static function dockerInfo(System $system): array
    {
        if (self::$dockerInfo !== null) {
            return self::$dockerInfo;
        }

        self::$dockerInfo = [];
        try {
            $raw = $system->exec(Privileged::docker()->info(), [], 30);
            $decoded = json_decode(trim($raw), true);
            if (is_array($decoded)) {
                self::$dockerInfo = $decoded;
            }
        } catch (\Throwable $e) {
            Log::debug('Telemetry could not read docker info: '.$e->getMessage());
        }

        return self::$dockerInfo;
    }

    public static function resetProbeCache(): void
    {
        self::$dockerInfo = null;
    }

    /**
     * Sysbox is what makes the dind template viable, so whether it is
     * registered is worth knowing when a whole install's deploys fail.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private static function runtimes(array $info): array
    {
        $runtimes = $info['Runtimes'] ?? null;
        if (! is_array($runtimes)) {
            return [];
        }
        $names = array_keys($runtimes);
        sort($names);

        return array_values(array_filter($names, 'is_string'));
    }

    /**
     * The container's own machine-id, since `cron` cannot enter the host's namespaces. A weak anchor,
     * stable only as long as the image, so it only contributes alongside the Docker daemon id.
     */
    private static function machineId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            if (is_readable($path)) {
                $value = trim((string) @file_get_contents($path));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array{model: ?string, cores: ?int}
     */
    private static function cpu(): array
    {
        if (! is_readable('/proc/cpuinfo')) {
            return ['model' => null, 'cores' => null];
        }

        $raw = (string) @file_get_contents('/proc/cpuinfo');
        $model = null;
        if (preg_match('/^model name\s*:\s*(.+)$/m', $raw, $m) === 1) {
            $model = trim($m[1]);
        }
        $cores = preg_match_all('/^processor\s*:/m', $raw);

        return ['model' => $model, 'cores' => $cores > 0 ? $cores : null];
    }

    private static function memTotalKb(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }
        $raw = (string) @file_get_contents('/proc/meminfo');
        if (preg_match('/^MemTotal:\s*(\d+)\s*kB/mi', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Contributes to the id, never transmitted. The engine already knows its
     * own public address, so this costs nothing to read.
     */
    private static function publicIp(): ?string
    {
        try {
            return self::str(Setting::get('default_ipv4'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function webserver(System $system): ?string
    {
        try {
            return self::str($system->webserver()->getCurrentWebserver());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function accountCount(): ?int
    {
        try {
            return User::query()->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
