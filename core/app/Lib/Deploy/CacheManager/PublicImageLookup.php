<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use GuzzleHttp\Client;

/**
 * Whether Docker Hub publishes a name without a registry host, asked through registry-proxy under the host's login.
 */
class PublicImageLookup
{
    /** @var callable(string): ?int url to HTTP status, null when nothing answered */
    private $head;

    public function __construct(?callable $head = null)
    {
        $this->head = $head ?? self::httpHead(...);
    }

    /** True when Docker Hub has $ref, false when it does not, null when that cannot be told. */
    public function onDockerHub(string $ref): ?bool
    {
        $url = self::manifestUrl($ref);
        if ($url === null) {
            return false;
        }
        $status = ($this->head)($url);

        return match (true) {
            $status === 200 => true,
            in_array($status, [401, 404], true) => false,
            default => null,
        };
    }

    /** registry-proxy's address for the manifest, as {@see RegistryImageConfig::sources()} names Docker Hub's. */
    private static function manifestUrl(string $ref): ?string
    {
        foreach (RegistryImageConfig::sources($ref) as [$base, $repository, $reference]) {
            if ($base === 'http://'.DindImageStore::PROXY_REGISTRY) {
                return "{$base}/v2/{$repository}/manifests/{$reference}";
            }
        }

        return null;
    }

    private static function httpHead(string $url): ?int
    {
        try {
            return (new Client(['connect_timeout' => 2, 'timeout' => 20, 'http_errors' => false]))
                ->head($url, ['headers' => ['Accept' => RegistryImageConfig::ACCEPT]])
                ->getStatusCode();
        } catch (\Throwable) {
            return null;
        }
    }
}
