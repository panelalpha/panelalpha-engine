<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\OperatorImageRef;
use App\Models\OperatorImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * Every project pulls from the cache registry before Docker Hub, so an
 * operator image may not take a name that stands in for someone else's.
 */
class OperatorImageRefTest extends TestCase
{
    private const CATALOGUE = ['node:22-bookworm-slim', 'nginx:alpine', 'composer:2'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'a short build name' => ['whmcs-dev-base:php8.3', OperatorImage::SOURCE_BUILD],
            'a namespaced local name' => ['acme-dev/base:1.0', OperatorImage::SOURCE_LOCAL],
            'a public image to pull' => ['debian:bookworm-slim', OperatorImage::SOURCE_PULL],
            'a pull from another registry' => ['ghcr.io/acme/tools:2', OperatorImage::SOURCE_PULL],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_name_nobody_else_uses_is_accepted(string $ref, string $source): void
    {
        $this->assertNull(OperatorImageRef::problem($ref, $source, self::CATALOGUE));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        return [
            'no tag' => ['whmcs-dev-base', OperatorImage::SOURCE_BUILD, OperatorImageRef::INVALID],
            'a digest' => ['debian@sha256:'.str_repeat('a', 64), OperatorImage::SOURCE_PULL, OperatorImageRef::INVALID],
            'upper case' => ['Whmcs:1', OperatorImage::SOURCE_BUILD, OperatorImageRef::INVALID],
            'an option' => ['--privileged:1', OperatorImage::SOURCE_BUILD, OperatorImageRef::INVALID],
            'our cache registry' => ['panelalpha-cache-registry:5000/x:1', OperatorImage::SOURCE_PULL, OperatorImageRef::RESERVED],
            'the host loopback registry' => [TestNetwork::LOOPBACK.':5000/x:1', OperatorImage::SOURCE_PULL, OperatorImageRef::RESERVED],
            'our own images' => ['panelalpha/php:8.3-x', OperatorImage::SOURCE_BUILD, OperatorImageRef::RESERVED],
            'Railpack images' => ['ghcr.io/railwayapp/railpack-builder:1', OperatorImage::SOURCE_PULL, OperatorImageRef::RESERVED],
            'a catalogue repository' => ['node:20', OperatorImage::SOURCE_BUILD, OperatorImageRef::RESERVED],
            'a catalogue repository spelt in full' => ['docker.io/library/nginx:1.27', OperatorImage::SOURCE_PULL, OperatorImageRef::RESERVED],
            'a registry host on a build' => ['ghcr.io/acme/base:1', OperatorImage::SOURCE_BUILD, OperatorImageRef::REGISTRY_HOST],
            'a registry host on a local' => ['localhost/base:1', OperatorImage::SOURCE_LOCAL, OperatorImageRef::REGISTRY_HOST],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_name_that_stands_in_for_another_is_refused(string $ref, string $source, string $code): void
    {
        $this->assertSame($code, OperatorImageRef::problem($ref, $source, self::CATALOGUE)['code'] ?? null);
    }

    public function test_docker_hub_spellings_collapse_to_the_short_name(): void
    {
        $this->assertSame('whmcs-dev-base:php8.3', OperatorImageRef::normalize('docker.io/library/whmcs-dev-base:php8.3'));
        $this->assertSame('acme/base:1', OperatorImageRef::normalize('index.docker.io/acme/base:1'));
        $this->assertSame('ghcr.io/acme/base:1', OperatorImageRef::normalize('ghcr.io/acme/base:1'));
    }
}
