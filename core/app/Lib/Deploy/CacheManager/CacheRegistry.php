<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use GuzzleHttp\Client;

/**
 * What panelalpha-cache-registry holds, and removing tags from it, over its
 * HTTP API as core addresses it. Removing a tag frees nothing by itself; the
 * garbage-collect after it does ({@see DindImageStore::garbageCollectArgv()}).
 */
final class CacheRegistry
{
    private const ACCEPT = 'application/vnd.oci.image.manifest.v1+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json, '
        . 'application/vnd.oci.image.index.v1+json, '
        . 'application/vnd.docker.distribution.manifest.list.v2+json';

    /** @var callable(string, string, array<string, string>): ?array{status: int, headers: array<string, string>, body: string} */
    private $send;

    private string $base;

    public function __construct(?callable $send = null, ?string $base = null)
    {
        $this->send = $send ?? self::httpSend(...);
        $this->base = $base ?? 'http://' . DindImageStore::CACHE_REGISTRY;
    }

    /**
     * Every `repository:tag` the registry holds, or null when it cannot be read.
     *
     * @return list<string>|null
     */
    public function refs(): ?array
    {
        // 3.x refuses a page over 1000 (PAGINATION_NUMBER_INVALID) and says
        // where the next one is in a Link header.
        $repositories = [];
        $path = '/v2/_catalog?n=1000';
        while ($path !== null) {
            $response = ($this->send)('GET', $this->base . $path, ['Accept' => 'application/json']);
            $page = ($response['status'] ?? 0) === 200 ? json_decode($response['body'] ?? '', true) : null;
            if (!is_array($page['repositories'] ?? null)) {
                return null;
            }
            array_push($repositories, ...$page['repositories']);
            $path = preg_match('/<([^>]+)>;\s*rel="next"/', $response['headers']['link'] ?? '', $m) === 1 ? $m[1] : null;
        }

        $refs = [];
        foreach ($repositories as $repository) {
            $tags = $this->json('GET', "/v2/{$repository}/tags/list")['tags'] ?? null;
            foreach (is_array($tags) ? $tags : [] as $tag) {
                $refs[] = "{$repository}:{$tag}";
            }
        }

        return $refs;
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
     * Which of the registry's refs to remove: everything prewarm does not keep,
     * except a ref whose digest a kept ref shares, since deleting by digest
     * would take the kept tag with it.
     *
     * @param array<string, ?string> $digests every registry ref and its digest
     * @param list<string> $keep
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
            if (!isset($keep[$ref]) && $digest !== null && !isset($keptDigests[$digest])) {
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
        $response = ($this->send)($method, $this->base . $path, ['Accept' => 'application/json']);
        if (($response['status'] ?? 0) !== 200) {
            return null;
        }
        $decoded = json_decode($response['body'] ?? '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function httpSend(string $method, string $url, array $headers): ?array
    {
        try {
            $response = (new Client(['connect_timeout' => 2, 'timeout' => 30, 'http_errors' => false]))
                ->request($method, $url, ['headers' => $headers]);
        } catch (\Throwable $e) {
            return null;
        }

        $flat = [];
        foreach ($response->getHeaders() as $name => $values) {
            $flat[strtolower($name)] = implode(', ', $values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $flat, 'body' => (string) $response->getBody()];
    }
}
