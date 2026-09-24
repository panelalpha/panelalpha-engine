<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use GuzzleHttp\Client;

/**
 * An image's own config, read from a registry without pulling it: the cache
 * registry first, then registry-proxy for Docker Hub images. For images no
 * local store holds yet, which since there is no host-side pull is most of them.
 */
final class RegistryImageConfig
{
    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, '
        . 'application/vnd.docker.distribution.manifest.list.v2+json, '
        . 'application/vnd.oci.image.manifest.v1+json, '
        . 'application/vnd.docker.distribution.manifest.v2+json';

    /** @var callable(string, string): ?string url and Accept header to body, null on any failure */
    private $get;

    private string $architecture;

    public function __construct(?callable $get = null, ?string $architecture = null)
    {
        $this->get = $get ?? self::httpGet(...);
        $this->architecture = $architecture ?? self::hostArchitecture();
    }

    /**
     * Ports $image declares, from the first registry that has it.
     *
     * @return list<int>
     */
    public function exposedPorts(string $image): array
    {
        foreach (self::sources($image) as [$base, $repository, $reference]) {
            $config = $this->config($base, $repository, $reference);
            if ($config !== null) {
                $ports = $config['config']['ExposedPorts'] ?? null;

                return is_array($ports) ? ImageTransfer::parseExposedPorts((string) json_encode($ports)) : [];
            }
        }

        return [];
    }

    /**
     * What pulling $image moves: the compressed layers of this platform's
     * manifest, as `docker manifest inspect --verbose` would have summed them,
     * but asked of our registries, so the question goes out under
     * registry-proxy's Docker Hub login instead of anonymously from core.
     * Null when neither has it.
     */
    public function downloadBytes(string $image): ?int
    {
        foreach (self::sources($image) as [$base, $repository, $reference]) {
            $layers = $this->manifest($base, $repository, $reference)['layers'] ?? null;
            if (!is_array($layers)) {
                continue;
            }
            $total = 0;
            foreach ($layers as $layer) {
                $size = is_array($layer) ? ($layer['size'] ?? null) : null;
                $total += is_int($size) && $size > 0 ? $size : 0;
            }
            if ($total > 0) {
                return $total;
            }
        }

        return null;
    }

    /**
     * Where to ask, in order, as [base URL, repository, tag or digest].
     *
     * The cache registry stores an image under the reference it was pushed
     * with; registry-proxy takes Docker Hub's own path, `library/` included.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function sources(string $image): array
    {
        if (!ImageTransfer::isSafeImageRef($image)) {
            return [];
        }

        [$name, $reference] = self::split($image);
        $sources = [['http://' . DindImageStore::CACHE_REGISTRY, $name, $reference]];
        $hub = self::dockerHubRepository($name);
        if ($hub !== null) {
            $sources[] = ['http://' . DindImageStore::PROXY_REGISTRY, $hub, $reference];
        }

        return $sources;
    }

    /**
     * @return array{0: string, 1: string} name and tag or digest
     */
    private static function split(string $image): array
    {
        $at = strpos($image, '@');
        if ($at !== false) {
            return [substr($image, 0, $at), substr($image, $at + 1)];
        }

        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            return [substr($image, 0, $colon), substr($image, $colon + 1)];
        }

        return [$image, 'latest'];
    }

    /** Docker Hub's path for $name, or null when the image lives on another registry. */
    private static function dockerHubRepository(string $name): ?string
    {
        $parts = explode('/', $name);
        $first = $parts[0];
        $isHost = count($parts) > 1
            && (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost');
        if ($isHost) {
            if (!in_array($first, ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true)) {
                return null;
            }
            array_shift($parts);
        }
        if (count($parts) === 1) {
            array_unshift($parts, 'library');
        }

        return implode('/', $parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $base, string $repository, string $reference): ?array
    {
        $configDigest = $this->manifest($base, $repository, $reference)['config']['digest'] ?? null;
        if (!is_string($configDigest) || $configDigest === '') {
            return null;
        }

        return $this->json("{$base}/v2/{$repository}/blobs/{$configDigest}", '*/*');
    }

    /**
     * This platform's manifest for $reference, through an index when there is one.
     *
     * @return array<string, mixed>|null
     */
    private function manifest(string $base, string $repository, string $reference): ?array
    {
        $manifest = $this->json("{$base}/v2/{$repository}/manifests/{$reference}", self::ACCEPT);
        if (isset($manifest['manifests']) && is_array($manifest['manifests'])) {
            $digest = $this->platformDigest($manifest['manifests']);

            return $digest === null ? null : $this->json("{$base}/v2/{$repository}/manifests/{$digest}", self::ACCEPT);
        }

        return $manifest;
    }

    /**
     * @param array<mixed> $entries
     */
    private function platformDigest(array $entries): ?string
    {
        foreach ($entries as $entry) {
            $platform = is_array($entry) ? ($entry['platform'] ?? []) : [];
            if (($platform['os'] ?? '') === 'linux' && ($platform['architecture'] ?? '') === $this->architecture) {
                return is_string($entry['digest'] ?? null) ? $entry['digest'] : null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $url, string $accept): ?array
    {
        $body = ($this->get)($url, $accept);
        if (!is_string($body)) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function httpGet(string $url, string $accept): ?string
    {
        // A cold proxy pays Hub's token handshake first: 5.0s measured for postgres.
        try {
            $response = (new Client(['connect_timeout' => 2, 'timeout' => 15, 'http_errors' => false]))
                ->get($url, ['headers' => ['Accept' => $accept]]);
        } catch (\Throwable $e) {
            return null;
        }

        return $response->getStatusCode() === 200 ? (string) $response->getBody() : null;
    }

    private static function hostArchitecture(): string
    {
        return match (php_uname('m')) {
            'aarch64', 'arm64' => 'arm64',
            default => 'amd64',
        };
    }
}
