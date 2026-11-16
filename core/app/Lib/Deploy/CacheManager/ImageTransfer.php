<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Engine\ImageStore;

/**
 * What an image transfer is allowed to be, and how many may run at once; engine-neutral.
 * The commands that actually move an image belong to {@see ImageStore}.
 */
class ImageTransfer
{
    /** Absolute ceiling: past this the disk, not the CPU, is the bottleneck. */
    public const MAX_CONCURRENCY = 4;

    /** Rough working set of one image being pulled and unpacked into an account. */
    private const MB_PER_STREAM = 512;

    /** Stand-in load average when /proc is unreadable — forces sequential. */
    public const LOAD_UNKNOWN = 1000000.0;

    /**
     * How many images to seed at once on this host, from current CPU and memory headroom.
     * An operator $override wins outright, clamped to the ceiling.
     */
    public static function concurrencyFor(
        int $cpus,
        float $load1,
        int $availableMemMb,
        int $imageCount,
        ?int $override = null
    ): int {
        if ($imageCount <= 0) {
            return 1;
        }
        if ($override !== null && $override > 0) {
            return max(1, min($override, self::MAX_CONCURRENCY, $imageCount));
        }

        $load = min(max(0.0, $load1), self::LOAD_UNKNOWN);
        $cpuHeadroom = max(1, $cpus) - (int) floor($load);
        $memHeadroom = (int) floor(max(0, $availableMemMb) / self::MB_PER_STREAM);

        $limit = min($cpuHeadroom, $memHeadroom, $imageCount, self::MAX_CONCURRENCY);

        return max(1, $limit);
    }

    /**
     * Host capacity readings; all three answer junk with a value that drives {@see concurrencyFor()} to sequential.
     */
    public static function parseCpuCount(string $procCpuinfo): int
    {
        $count = preg_match_all('/^processor\s*:/mi', $procCpuinfo);

        return $count > 0 ? $count : 1;
    }

    /** Junk reads as LOAD_UNKNOWN: large enough to force sequential, small enough to stay a safe int. */
    public static function parseLoadAvg(string $procLoadavg): float
    {
        $first = strtok(trim($procLoadavg), " \t\n");

        return is_string($first) && is_numeric($first) ? (float) $first : self::LOAD_UNKNOWN;
    }

    public static function parseMemAvailableMb(string $procMeminfo): int
    {
        if (preg_match('/^MemAvailable:\s+(\d+)\s*kB/mi', $procMeminfo, $m) === 1) {
            return (int) ((int) $m[1] / 1024);
        }

        return 0;
    }

    /**
     * Container ports out of `{{json .Config.ExposedPorts}}` ({"5432/tcp":{}}). Anything unparseable
     * yields nothing: a missing hint is recoverable, a wrong one is not.
     *
     * @return list<int>
     */
    public static function parseExposedPorts(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        if (! is_array($decoded)) {
            return [];
        }

        $ports = [];
        foreach (array_keys($decoded) as $spec) {
            $port = (int) explode('/', (string) $spec, 2)[0];
            if ($port > 0) {
                $ports[] = $port;
            }
        }

        return array_values(array_unique($ports));
    }

    /**
     * Compressed download size from `docker manifest inspect --verbose`, linux/amd64 only, or null.
     * A single-platform image inspects to one object, not a list.
     */
    public static function parseManifestSize(string $json): ?int
    {
        $decoded = json_decode(trim($json), true);
        if (! is_array($decoded)) {
            return null;
        }
        if (isset($decoded['Descriptor']) || isset($decoded['layers'])) {
            $decoded = [$decoded];
        }

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! self::isLinuxAmd64($entry)) {
                continue;
            }
            $total = self::layerBytes($entry);
            if ($total > 0) {
                return $total;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function layerBytes(array $entry): int
    {
        $layers = $entry['OCIManifest']['layers']
            ?? $entry['SchemaV2Manifest']['layers']
            ?? $entry['layers']
            ?? null;
        if (! is_array($layers)) {
            return 0;
        }
        $total = 0;
        foreach ($layers as $layer) {
            $size = is_array($layer) ? ($layer['size'] ?? null) : null;
            if (is_int($size) && $size > 0) {
                $total += $size;
            }
        }

        return $total;
    }

    /**
     * No platform block means single-platform: whatever the daemon asked for, which is this host.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function isLinuxAmd64(array $entry): bool
    {
        $platform = $entry['Descriptor']['platform'] ?? null;
        if (! is_array($platform)) {
            return true;
        }

        return ($platform['architecture'] ?? null) === 'amd64'
            && ($platform['os'] ?? null) === 'linux';
    }

    /**
     * A reference safe to hand to docker: `[registry[:port]/]repository[:tag][@sha256:…]` with a tag,
     * a digest or both, and no leading dash, which docker would read as a flag.
     */
    public static function isSafeImageRef(string $image): bool
    {
        if ($image === '' || $image[0] === '-') {
            return false;
        }
        $registry = '(?:[a-z0-9.-]+(?::[0-9]+)?/)?';
        $repository = '[a-z0-9._-]+(?:/[a-z0-9._-]+)*';
        $digest = '@sha256:[a-f0-9]{64}';

        return preg_match(
            '#^'.$registry.$repository.'(?::[a-z0-9._-]+(?:'.$digest.')?|'.$digest.')$#i',
            $image
        ) === 1;
    }

    /**
     * `name:tag@sha256:…` as `name@sha256:…`, anything else unchanged: docker
     * pulls and stores a reference carrying both by its digest alone.
     */
    public static function preferDigest(string $image): string
    {
        $at = strpos($image, '@');
        if ($at === false) {
            return $image;
        }
        $name = substr($image, 0, $at);
        $slash = strrpos($name, '/');
        $colon = strrpos($name, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $name = substr($name, 0, $colon);
        }

        return $name.substr($image, $at);
    }

    /**
     * Spell out the implicit :latest (never on a digest) and drop a tag beside a digest, as docker
     * records it. Null for an unexpanded ${VAR}, or a reference docker would not accept.
     */
    public static function normalizeImageRef(mixed $image): ?string
    {
        if (! is_string($image)) {
            return null;
        }
        $image = trim($image);
        if ($image === '' || str_contains($image, '$')) {
            return null;
        }
        if (! str_contains($image, '@') && ! str_contains(basename($image), ':')) {
            $image .= ':latest';
        }

        return self::isSafeImageRef($image) ? self::preferDigest($image) : null;
    }
}
