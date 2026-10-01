<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
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
        . 'application/vnd.docker.distribution.manifest.v2+json, '
        . 'application/vnd.oci.image.index.v1+json, '
        . 'application/vnd.docker.distribution.manifest.list.v2+json';

    /** @var callable(string, string, array<string, string>): ?array{status: int, headers: array<string, string>, body: string} */
    private $send;

    private string $base;

    public function __construct(?callable $send = null, ?string $base = null)
    {
        $this->send = $send ?? self::hostSend(...);
        $this->base = $base ?? 'http://' . DindImageStore::HOST_CACHE_REGISTRY;
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
     * curl run in the host's network namespace; core's own loopback is not the host's.
     *
     * @param array<string, string> $headers
     * @return list<string>
     */
    public static function hostArgv(string $method, string $url, array $headers): array
    {
        $argv = [
            'sudo', 'nsenter', '--target', '1', '--net',
            'curl', '-sS', '-i', '--connect-timeout', '2', '--max-time', '30',
        ];
        // -X HEAD would wait for a body that never comes.
        array_push($argv, ...($method === 'HEAD' ? ['--head'] : ['-X', $method]));
        foreach ($headers as $name => $value) {
            array_push($argv, '-H', "{$name}: {$value}");
        }
        $argv[] = $url;

        return $argv;
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
     * @param array<string, string> $headers
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
