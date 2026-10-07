<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * What an image transfer is allowed to be, and how many may run at once.
 *
 * Reference validation, the host-capacity readings and the concurrency
 * heuristic are engine-neutral. The commands that actually move an image
 * belong to {@see \App\Lib\Deploy\Engine\ImageStore}.
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
     * How many images to seed at once on *this* host, right now.
     *
     * Seeding decompresses, so it is CPU-bound with an I/O tail — a 2-core VPS
     * running the panel too has no room for four at once. The limit is derived,
     * never fixed: CPU headroom is cores minus the whole cores the 1-minute
     * load average accounts for, memory allows one stream per ~512MB actually
     * available, and never more streams than images or MAX_CONCURRENCY. An
     * operator $override wins outright, clamped to the ceiling.
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
     * Host capacity readings, parsed in PHP so the heuristic stays testable.
     * All three tolerate junk by returning a value that drives
     * {@see concurrencyFor()} to sequential.
     */
    public static function parseCpuCount(string $procCpuinfo): int
    {
        $count = preg_match_all('/^processor\s*:/mi', $procCpuinfo);

        return $count > 0 ? $count : 1;
    }

    public static function parseLoadAvg(string $procLoadavg): float
    {
        $first = strtok(trim($procLoadavg), " \t\n");

        // Assume the worst: large enough to force sequential, small enough to
        // stay a safe int.
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
     * Container ports out of `docker image inspect --format {{json
     * .Config.ExposedPorts}}`, whose shape is {"5432/tcp":{}}. Anything
     * unparseable yields nothing: a missing hint is recoverable, a wrong one
     * is not.
     *
     * @return list<int>
     */
    public static function parseExposedPorts(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
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
     * Download size of an image from `docker manifest inspect --verbose`, in
     * bytes, or null when the output says nothing we can use.
     *
     * Compressed layer sizes — what the pull moves, roughly a third of the
     * on-disk cost. Only the linux/amd64 entry counts: summing a manifest list
     * would multiply the answer by the publisher's architectures.
     */
    public static function parseManifestSize(string $json): ?int
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            return null;
        }
        // A single-platform image inspects to one object, not a list.
        if (isset($decoded['Descriptor']) || isset($decoded['layers'])) {
            $decoded = [$decoded];
        }

        foreach ($decoded as $entry) {
            if (!is_array($entry) || !self::isLinuxAmd64($entry)) {
                continue;
            }
            $layers = $entry['OCIManifest']['layers']
                ?? $entry['SchemaV2Manifest']['layers']
                ?? $entry['layers']
                ?? null;
            if (!is_array($layers)) {
                continue;
            }
            $total = 0;
            foreach ($layers as $layer) {
                $size = is_array($layer) ? ($layer['size'] ?? null) : null;
                if (is_int($size) && $size > 0) {
                    $total += $size;
                }
            }
            if ($total > 0) {
                return $total;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function isLinuxAmd64(array $entry): bool
    {
        $platform = $entry['Descriptor']['platform'] ?? null;
        // An image with no platform block is single-platform: it is whatever
        // the daemon asked for, which is this host.
        if (!is_array($platform)) {
            return true;
        }

        return ($platform['architecture'] ?? null) === 'amd64'
            && ($platform['os'] ?? null) === 'linux';
    }

    /**
     * A reference safe to hand to docker. Accepts a tag, a digest or both, as
     * recipes pin images (`name:tag@sha256:…`); rejects a leading dash, which
     * docker would read as a flag.
     */
    public static function isSafeImageRef(string $image): bool
    {
        if ($image === '' || $image[0] === '-') {
            return false;
        }
        // [registry[:port]/]repository[:tag][@sha256:…], at least one of the two
        $registry = '(?:[a-z0-9.-]+(?::[0-9]+)?/)?';
        $repository = '[a-z0-9._-]+(?:/[a-z0-9._-]+)*';
        $digest = '@sha256:[a-f0-9]{64}';

        return preg_match(
            '#^' . $registry . $repository . '(?::[a-z0-9._-]+(?:' . $digest . ')?|' . $digest . ')$#i',
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

        return $name . substr($image, $at);
    }

    /**
     * Spell out the implicit :latest, and drop a tag beside a digest, so the
     * ref matches what docker records. Null for an unexpanded ${VAR}, or a
     * reference docker would not accept.
     */
    public static function normalizeImageRef(mixed $image): ?string
    {
        if (!is_string($image)) {
            return null;
        }
        $image = trim($image);
        if ($image === '' || str_contains($image, '$')) {
            return null;
        }
        // A digest is already exact; :latest on top of one resolves to nothing.
        if (!str_contains($image, '@') && !str_contains(basename($image), ':')) {
            $image .= ':latest';
        }

        return self::isSafeImageRef($image) ? self::preferDigest($image) : null;
    }
}
