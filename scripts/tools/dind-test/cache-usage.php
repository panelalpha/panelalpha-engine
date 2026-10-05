#!/usr/bin/env php
<?php
/**
 * Report how much each shared build-cache tag was actually used.
 *
 * The engine keeps no cache statistics of its own — no hit counters, no
 * per-tag metrics — and a Railpack build's buildx progress never reaches the
 * deploy log (it runs through Shell::execAsUser()). The one place the usage is
 * observable is the cache registry's own access log, so that is what this
 * reads.
 *
 * It separates two things that are easy to conflate:
 *
 *   MANIFEST READS  the build asked the registry about a tag. Every Railpack
 *                   build asks about *every* stack, because
 *                   BuildCache::tenantFlags() attaches one --cache-from
 *                   per stack unconditionally. A read means "considered", not
 *                   "used".
 *   BLOB PULLS / MB  cached layer data actually transferred. This is the real
 *                   usage number: a tag with reads but ~0 MB did nothing for
 *                   the build.
 *
 * Usage:
 *   php cache-usage.php --mark                 # print the current log offset
 *   ... run one or more deploys ...
 *   php cache-usage.php --from=<offset>        # report on everything since
 *   php cache-usage.php                        # report on the whole log
 */

declare(strict_types=1);

$options = getopt('', ['mark', 'from::', 'registry::', 'host::', 'help']);

if (isset($options['help'])) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cache-usage.php --mark            print current registry log offset\n");
    fwrite(STDERR, "  php cache-usage.php --from=OFFSET     report on lines after OFFSET\n");
    fwrite(STDERR, "  php cache-usage.php                   report on the whole log\n");
    fwrite(STDERR, "  --registry=NAME  cache registry container (default panelalpha-cache-registry)\n");
    fwrite(STDERR, "  --host=URL       registry API base (default http://localhost:5000)\n");
    exit(0);
}

$container = $options['registry'] ?? 'panelalpha-cache-registry';
$apiBase = rtrim($options['host'] ?? 'http://localhost:5000', '/');
$repository = 'panelalpha-cache';

$log = shell_exec('docker logs ' . escapeshellarg($container) . ' 2>&1');
if (!is_string($log)) {
    fwrite(STDERR, "Cannot read logs from {$container} — is the cache registry running?\n");
    exit(1);
}
$lines = preg_split('/\r?\n/', $log) ?: [];

if (isset($options['mark'])) {
    echo count($lines), "\n";
    exit(0);
}

$from = (int) ($options['from'] ?? 0);
if ($from > 0) {
    $lines = array_slice($lines, $from);
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

$tagList = registryJson("{$apiBase}/v2/{$repository}/tags/list");
$tags = $tagList['tags'] ?? [];
if ($tags === []) {
    fwrite(STDERR, "No tags in {$repository}. Nothing has warmed the shared cache yet.\n");
    exit(1);
}
sort($tags);

// digest => tags that contain it, and the digest's size
$owners = [];
$sizes = [];
foreach ($tags as $tag) {
    $manifest = registryJson("{$apiBase}/v2/{$repository}/manifests/{$tag}");
    if ($manifest === null) {
        fwrite(STDERR, "  ! could not read manifest for {$tag}\n");
        continue;
    }
    $blobs = $manifest['layers'] ?? [];
    if (isset($manifest['config'])) {
        $blobs[] = $manifest['config'];
    }
    foreach ($blobs as $blob) {
        $digest = $blob['digest'] ?? null;
        if ($digest === null) {
            continue;
        }
        $owners[$digest][$tag] = true;
        $sizes[$digest] = $blob['size'] ?? 0;
    }
}

$manifestReads = array_fill_keys($tags, 0);
$blobRequests = [];
foreach ($lines as $line) {
    if (!str_contains($line, $repository)) {
        continue;
    }
    if (preg_match('#' . preg_quote($repository, '#') . '/manifests/([^/"\s]+)#', $line, $m) === 1) {
        if (isset($manifestReads[$m[1]])) {
            $manifestReads[$m[1]]++;
        }
        continue;
    }
    if (preg_match('#' . preg_quote($repository, '#') . '/blobs/(sha256:[0-9a-f]{64})#', $line, $m) === 1) {
        $blobRequests[$m[1]] = ($blobRequests[$m[1]] ?? 0) + 1;
    }
}

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

$totalManifest = array_sum($manifestReads);
$totalBlob = array_sum($blobRequests);

printf("Shared build cache: %s/%s\n", $apiBase, $repository);
printf("Log window: %s\n\n", $from > 0 ? "lines after {$from}" : 'entire registry log');
printf("%-26s %14s %12s %10s   %s\n", 'CACHE TAG', 'MANIFEST READS', 'BLOB PULLS', 'MB', 'VERDICT');
foreach ($tags as $tag) {
    $mb = $bytes[$tag] / 1048576;
    // A tag read but delivering no payload cost a round trip and nothing else.
    $verdict = $manifestReads[$tag] === 0
        ? 'never consulted'
        : ($mb >= 1.0 ? 'USED' : 'consulted, no payload');
    printf("%-26s %14d %12d %10.1f   %s\n", $tag, $manifestReads[$tag], $pulls[$tag], $mb, $verdict);
}
printf("\n%d manifest read(s), %d blob request(s)", $totalManifest, $totalBlob);
if ($unattributed > 0) {
    printf(", %d blob request(s) not owned by any tag", $unattributed);
}
echo "\n";

// Cache warming (BuildCacheWarmPlan, via BuildCache::warmFlags()) is
// the one thing allowed to write here, and it does so from synthetic projects.
// A *tenant deploy* must never write: that would export layers holding customer
// source into a registry every other account can read. So writes are only
// meaningful inside a deploy window — i.e. when --from scopes the log.
$writes = 0;
foreach ($lines as $line) {
    if (preg_match('/"(PUT|POST|PATCH)\s/', $line) === 1 && str_contains($line, $repository)) {
        $writes++;
    }
}
if ($from > 0) {
    printf(
        "Writes during this window: %d%s\n",
        $writes,
        $writes === 0
            ? ' (read-only — correct for a tenant deploy)'
            : ' — a tenant deploy must not write to the shared cache; INVESTIGATE'
    );
} else {
    printf(
        "Writes over the whole log: %d (expected: cache warming writes these tags;"
        . " re-run with --from to check a single deploy)\n",
        $writes
    );
}
