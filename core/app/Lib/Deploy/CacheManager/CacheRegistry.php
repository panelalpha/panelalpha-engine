<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use App\System;
use App\System\Privileged;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

/**
 * What panelalpha-cache-registry holds, and removing tags from it, over the
 * writer's HTTP API (the accounts' instance is read-only). Removing a tag frees nothing by itself; the
 * garbage-collect after it does ({@see DindImageStore::garbageCollectArgv()}).
 *
 * The writer listens on the host's loopback only, so requests are made from
 * the host's network namespace ({@see hostArgv()}).
 */
final class CacheRegistry
{
    private const ACCEPT = 'application/vnd.oci.image.manifest.v1+json, '
        .'application/vnd.docker.distribution.manifest.v2+json, '
        .'application/vnd.oci.image.index.v1+json, '
        .'application/vnd.docker.distribution.manifest.list.v2+json';

    /** @var callable(string, string, array<string, string>): ?array{status: int, headers: array<string, string>, body: string} */
    private $send;

    private string $base;

    /** Where the writer keeps each repository's tags, under `<repository>/_manifests/tags/<tag>/current/link`. */
    private const REPOSITORIES = '/var/lib/registry/docker/registry/v2/repositories';

    public function __construct(?callable $send = null, ?string $base = null)
    {
        $this->send = $send ?? self::hostSend(...);
        $this->base = $base ?? 'http://'.DindImageStore::HOST_CACHE_REGISTRY;
    }

    /**
     * The accounts' read-only instance, asked over the engine network: enough
     * for listing and digests, and needs no way into the host.
     */
    public static function readOnly(): self
    {
        return new self(self::networkSend(...), 'http://'.DindImageStore::CACHE_REGISTRY);
    }

    /**
     * Every `repository:tag` the registry holds, or null when it cannot be read; 3.x pages at most 1000 and links the next.
     *
     * @return list<string>|null
     */
    public function refs(): ?array
    {
        $repositories = $this->catalogRepositories();
        if ($repositories === null) {
            return null;
        }

        $refs = [];
        foreach ($repositories as $repository) {
            foreach ($this->tagsOf($repository) as $tag) {
                $refs[] = "{$repository}:{$tag}";
            }
        }

        return $refs;
    }

    /**
     * Every repository the catalog lists, paging the at-most-1000 a 3.x registry answers; null when it cannot be read.
     *
     * @return list<string>|null
     */
    private function catalogRepositories(): ?array
    {
        $repositories = [];
        $path = '/v2/_catalog?n=1000';
        while ($path !== null) {
            $response = ($this->send)('GET', $this->base.$path, ['Accept' => 'application/json']);
            $page = ($response['status'] ?? 0) === 200 ? json_decode($response['body'] ?? '', true) : null;
            if (! is_array($page['repositories'] ?? null)) {
                return null;
            }
            array_push($repositories, ...$page['repositories']);
            $path = preg_match('/<([^>]+)>;\s*rel="next"/', $response['headers']['link'] ?? '', $m) === 1 ? $m[1] : null;
        }

        return $repositories;
    }

    /** @return list<string> */
    private function tagsOf(string $repository): array
    {
        $tags = $this->json('GET', "/v2/{$repository}/tags/list")['tags'] ?? null;

        return array_values(array_filter(is_array($tags) ? $tags : [], 'is_string'));
    }

    /**
     * A repository the catalog still lists after its last tag and manifest are gone: the registry keeps the directory,
     * so `/v2/_catalog` reads the name with `tags: null`. Empty when the catalog cannot be read.
     *
     * @return list<string>
     */
    public function emptyRepositories(): array
    {
        $empty = [];
        foreach ($this->catalogRepositories() ?? [] as $repository) {
            if ($this->tagsOf($repository) === []) {
                $empty[] = $repository;
            }
        }

        return $empty;
    }

    /** Drop every repository left with no tags, so a removed image leaves no empty name in the catalog. */
    public function removeEmptyRepositories(System $system): void
    {
        foreach ($this->emptyRepositories() as $repository) {
            $system->exec(self::removeRepositoryArgv($repository), [], 120);
        }
    }

    /** @return list<string> */
    public static function removeRepositoryArgv(string $repository): array
    {
        return Privileged::docker()->removeRegistryRepository(
            DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER,
            self::REPOSITORIES,
            $repository,
        );
    }

    public function digest(string $ref): ?string
    {
        [$repository, $tag] = self::split($ref);
        $response = ($this->send)('HEAD', "{$this->base}/v2/{$repository}/manifests/{$tag}", ['Accept' => self::ACCEPT]);
        $digest = $response['headers']['docker-content-digest'] ?? null;

        return ($response['status'] ?? 0) === 200 && is_string($digest) && $digest !== '' ? $digest : null;
    }

    /** Remove the manifest $ref points at; every tag sharing that digest goes with it. */
    public function delete(string $ref, string $digest): bool
    {
        [$repository] = self::split($ref);
        $response = ($this->send)('DELETE', "{$this->base}/v2/{$repository}/manifests/{$digest}", []);

        return in_array($response['status'] ?? 0, [200, 202], true);
    }

    /**
     * Every tag with its digest and last push time, read from each tag's `current/link` in the writer's storage,
     * since the registry API has no times.
     *
     * @return list<string>
     */
    public static function tagListingArgv(): array
    {
        return Privileged::docker()->registryTagLinks(DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER, self::REPOSITORIES);
    }

    /**
     * {@see tagListingArgv()} output as `repository:tag` => digest and push time.
     *
     * @return array<string, array{digest: string, pushed: int}>
     */
    public static function parseTagListing(string $output): array
    {
        $pattern = '#^(\d+) (sha256:[0-9a-f]{64}) '.preg_quote(self::REPOSITORIES, '#')
            .'/(.+)/_manifests/tags/([^/]+)/current/link$#';
        $tags = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            if (preg_match($pattern, trim($line), $m) === 1) {
                $tags[$m[3].':'.$m[4]] = ['digest' => $m[2], 'pushed' => (int) $m[1]];
            }
        }
        ksort($tags);

        return $tags;
    }

    /**
     * Which of the registry's refs to remove: everything prewarm does not keep,
     * except a ref whose digest a kept ref shares, since deleting by digest
     * would take the kept tag with it.
     *
     * @param  array<string, ?string>  $digests  every registry ref and its digest
     * @param  list<string>  $keep
     * @return list<string>
     */
    public static function unwanted(array $digests, array $keep): array
    {
        $keep = array_flip($keep);
        $keptDigests = [];
        foreach ($digests as $ref => $digest) {
            if (isset($keep[$ref]) && $digest !== null) {
                $keptDigests[$digest] = true;
            }
        }

        $unwanted = [];
        foreach ($digests as $ref => $digest) {
            if (! isset($keep[$ref]) && $digest !== null && ! isset($keptDigests[$digest])) {
                $unwanted[] = (string) $ref;
            }
        }

        return $unwanted;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $ref): array
    {
        $colon = strrpos($ref, ':');
        $slash = strrpos($ref, '/');
        if ($colon === false || ($slash !== false && $colon < $slash)) {
            return [$ref, 'latest'];
        }

        return [substr($ref, 0, $colon), substr($ref, $colon + 1)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $method, string $path): ?array
    {
        $response = ($this->send)($method, $this->base.$path, ['Accept' => 'application/json']);
        if (($response['status'] ?? 0) !== 200) {
            return null;
        }
        $decoded = json_decode($response['body'] ?? '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * curl run in the host's network namespace; core's own loopback is not the host's.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    public static function hostArgv(string $method, string $url, array $headers): array
    {
        return Privileged::host()->httpRequest($method, $url, $headers);
    }

    /**
     * curl -i output as a status, lower-cased headers and the body.
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function parseResponse(string $raw): ?array
    {
        $split = preg_split('/\r?\n\r?\n/', $raw, 2);
        $lines = preg_split('/\r?\n/', $split[0]);
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0] ?? '', $m) !== 1) {
            return null;
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return ['status' => (int) $m[1], 'headers' => $headers, 'body' => $split[1] ?? ''];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function networkSend(string $method, string $url, array $headers): ?array
    {
        try {
            $response = (new Client(['connect_timeout' => 2, 'timeout' => 30, 'http_errors' => false]))
                ->request($method, $url, ['headers' => $headers]);
        } catch (\Throwable) {
            return null;
        }

        $lowered = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lowered[strtolower($name)] = implode(', ', $values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $lowered, 'body' => (string) $response->getBody()];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function hostSend(string $method, string $url, array $headers): ?array
    {
        $process = new Process(self::hostArgv($method, $url, $headers));
        $process->setTimeout(40);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        return $process->isSuccessful() ? self::parseResponse($process->getOutput()) : null;
    }
}
