#!/usr/bin/env php
<?php
/**
 * Report how much each shared build-cache tag was actually used.
 *
 * The engine keeps no cache statistics of its own — no hit counters, no
 * per-tag metrics. What the shared registry serves is base images by tag, and
 * the one place their use is observable is the registry's own access log, so
 * that is what this reads.
 *
 * It separates two things that are easy to conflate:
 *
 *   MANIFEST READS  something asked the registry about a tag. A read means
 *                   "considered", not "used".
 *   BLOB PULLS / MB  layer data actually transferred. This is the real usage
 *                   number: a tag with reads but ~0 MB did nothing.
 *
 * Note: Railpack builds no longer read or write these tags — RailpackStrategy
 * issues no --cache-from/--cache-to (see AGENTS.md §6a). Any railpack-* rows
 * here are from an older build.
 *
 * Usage:
 *   php cache-usage.php --mark                 # print the current log offset
 *   ... run one or more deploys ...
 *   php cache-usage.php --from=<offset>        # report on everything since
 *   php cache-usage.php                        # report on the whole log
 */

declare(strict_types=1);

const REPOSITORY = 'panelalpha-cache';

function printUsage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cache-usage.php --mark            print current registry log offset\n");
    fwrite(STDERR, "  php cache-usage.php --from=OFFSET     report on lines after OFFSET\n");
    fwrite(STDERR, "  php cache-usage.php                   report on the whole log\n");
    fwrite(STDERR, "  --registry=NAME  cache registry container (default panelalpha-cache-registry)\n");
    fwrite(STDERR, "  --host=URL       registry API base (default http://localhost:5000)\n");
}

/** @return list<string>|null the registry container's log, a line each; null when it cannot be read */
function registryLogLines(string $container): ?array
{
    $log = shell_exec('docker logs ' . escapeshellarg($container) . ' 2>&1');
    if (!is_string($log)) {
        return null;
    }

    return preg_split('/\r?\n/', $log) ?: [];
}

/** Fetch JSON from the registry API, following the OCI accept headers. */
function registryJson(string $url): ?array
{
    $context = stream_context_create(['http' => [
        'header' => "Accept: application/vnd.oci.image.manifest.v1+json,"
            . "application/vnd.oci.image.index.v1+json,"
            . "application/vnd.docker.distribution.manifest.v2+json\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/** @return list<string> the repository's tags, sorted */
function registryTags(string $apiBase): array
{
    $tagList = registryJson("{$apiBase}/v2/" . REPOSITORY . '/tags/list');
    $tags = $tagList['tags'] ?? [];
    sort($tags);

    return $tags;
}

/**
 * Which tags hold each blob, and its size.
 *
 * @param list<string> $tags
 * @return array{0: array<string, array<string, true>>, 1: array<string, int>} owners and sizes, by digest
 */
function blobOwnership(string $apiBase, array $tags): array
{
    $owners = [];
    $sizes = [];
    foreach ($tags as $tag) {
        $manifest = registryJson("{$apiBase}/v2/" . REPOSITORY . "/manifests/{$tag}");
        if ($manifest === null) {
            fwrite(STDERR, "  ! could not read manifest for {$tag}\n");
            continue;
        }
        foreach (manifestBlobs($manifest) as $blob) {
            $digest = $blob['digest'] ?? null;
            if ($digest === null) {
                continue;
            }
            $owners[$digest][$tag] = true;
            $sizes[$digest] = $blob['size'] ?? 0;
        }
    }

    return [$owners, $sizes];
}

/** @return list<array> a manifest's layers and its config */
function manifestBlobs(array $manifest): array
{
    $blobs = $manifest['layers'] ?? [];
    if (isset($manifest['config'])) {
        $blobs[] = $manifest['config'];
    }

    return $blobs;
}

/**
 * Manifest reads per known tag, and blob requests per digest, from the log.
 *
 * @param list<string> $lines
 * @param list<string> $tags
 * @return array{0: array<string, int>, 1: array<string, int>}
 */
function countRequests(array $lines, array $tags): array
{
    $quoted = preg_quote(REPOSITORY, '#');
    $manifestReads = array_fill_keys($tags, 0);
    $blobRequests = [];
    foreach ($lines as $line) {
        if (!str_contains($line, REPOSITORY)) {
            continue;
        }
        if (preg_match('#' . $quoted . '/manifests/([^/"\s]+)#', $line, $m) === 1) {
            if (isset($manifestReads[$m[1]])) {
                $manifestReads[$m[1]]++;
            }
            continue;
        }
        if (preg_match('#' . $quoted . '/blobs/(sha256:[0-9a-f]{64})#', $line, $m) === 1) {
            $blobRequests[$m[1]] = ($blobRequests[$m[1]] ?? 0) + 1;
        }
    }

    return [$manifestReads, $blobRequests];
}

/**
 * Blob pulls and bytes per tag, and the requests for blobs no tag owns.
 *
 * @param list<string> $tags
 * @param array<string, int> $blobRequests
 * @param array<string, array<string, true>> $owners
 * @param array<string, int> $sizes
 * @return array{0: array<string, int>, 1: array<string, int>, 2: int}
 */
function attributePulls(array $tags, array $blobRequests, array $owners, array $sizes): array
{
    $pulls = array_fill_keys($tags, 0);
    $bytes = array_fill_keys($tags, 0);
    $unattributed = 0;
    foreach ($blobRequests as $digest => $count) {
        if (!isset($owners[$digest])) {
            $unattributed += $count;
            continue;
        }
        foreach (array_keys($owners[$digest]) as $tag) {
            $pulls[$tag] += $count;
            $bytes[$tag] += $sizes[$digest] ?? 0;
        }
    }

    return [$pulls, $bytes, $unattributed];
}

/** A tag read but delivering no payload cost a round trip and nothing else. */
function verdict(int $manifestReads, float $mb): string
{
    if ($manifestReads === 0) {
        return 'never consulted';
    }

    return $mb >= 1.0 ? 'USED' : 'consulted, no payload';
}

/**
 * @param list<string> $tags
 * @param array<string, int> $manifestReads
 * @param array<string, int> $pulls
 * @param array<string, int> $bytes
 */
function printTagTable(array $tags, array $manifestReads, array $pulls, array $bytes): void
{
    printf("%-26s %14s %12s %10s   %s\n", 'CACHE TAG', 'MANIFEST READS', 'BLOB PULLS', 'MB', 'VERDICT');
    foreach ($tags as $tag) {
        $mb = $bytes[$tag] / 1048576;
        printf("%-26s %14d %12d %10.1f   %s\n", $tag, $manifestReads[$tag], $pulls[$tag], $mb, verdict($manifestReads[$tag], $mb));
    }
}

/** @param list<string> $lines */
function countWrites(array $lines): int
{
    $writes = 0;
    foreach ($lines as $line) {
        if (preg_match('/"(PUT|POST|PATCH)\s/', $line) === 1 && str_contains($line, REPOSITORY)) {
            $writes++;
        }
    }

    return $writes;
}

/**
 * Prewarm and our own on-demand images write these tags, so a write only means
 * something inside a deploy window (--from): a tenant deploy must never write.
 */
function printWrites(int $writes, int $from): void
{
    if ($from > 0) {
        printf(
            "Writes during this window: %d%s\n",
            $writes,
            $writes === 0
                ? ' (read-only — correct for a tenant deploy)'
                : ' — a tenant deploy must not write to the shared cache; INVESTIGATE'
        );

        return;
    }
    printf(
        "Writes over the whole log: %d (expected: cache warming writes these tags;"
        . " re-run with --from to check a single deploy)\n",
        $writes
    );
}

function main(): int
{
    $options = getopt('', ['mark', 'from::', 'registry::', 'host::', 'help']);
    if (isset($options['help'])) {
        printUsage();

        return 0;
    }

    $container = $options['registry'] ?? 'panelalpha-cache-registry';
    $apiBase = rtrim($options['host'] ?? 'http://localhost:5000', '/');

    $lines = registryLogLines($container);
    if ($lines === null) {
        fwrite(STDERR, "Cannot read logs from {$container} — is the cache registry running?\n");

        return 1;
    }
    if (isset($options['mark'])) {
        echo count($lines), "\n";

        return 0;
    }

    $from = (int) ($options['from'] ?? 0);
    if ($from > 0) {
        $lines = array_slice($lines, $from);
    }

    $tags = registryTags($apiBase);
    if ($tags === []) {
        fwrite(STDERR, 'No tags in ' . REPOSITORY . ". Nothing has warmed the shared cache yet.\n");

        return 1;
    }

    [$owners, $sizes] = blobOwnership($apiBase, $tags);
    [$manifestReads, $blobRequests] = countRequests($lines, $tags);
    [$pulls, $bytes, $unattributed] = attributePulls($tags, $blobRequests, $owners, $sizes);

    printf("Shared build cache: %s/%s\n", $apiBase, REPOSITORY);
    printf("Log window: %s\n\n", $from > 0 ? "lines after {$from}" : 'entire registry log');
    printTagTable($tags, $manifestReads, $pulls, $bytes);
    printf("\n%d manifest read(s), %d blob request(s)", array_sum($manifestReads), array_sum($blobRequests));
    if ($unattributed > 0) {
        printf(", %d blob request(s) not owned by any tag", $unattributed);
    }
    echo "\n";
    printWrites(countWrites($lines), $from);

    return 0;
}

exit(main());
