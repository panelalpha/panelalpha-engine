<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PublicImageLookup;
use PHPUnit\Framework\TestCase;

/** Whether Docker Hub publishes a name, asked of registry-proxy. */
class PublicImageLookupTest extends TestCase
{
    public function test_an_official_name_is_asked_under_library(): void
    {
        $asked = [];
        $lookup = new PublicImageLookup(function (string $url) use (&$asked): int {
            $asked[] = $url;

            return 200;
        });

        $this->assertTrue($lookup->onDockerHub('whmcs-dev-base:php8.3'));
        $this->assertTrue($lookup->onDockerHub('acme/base:1'));
        $this->assertSame([
            'http://panelalpha-registry-proxy:5000/v2/library/whmcs-dev-base/manifests/php8.3',
            'http://panelalpha-registry-proxy:5000/v2/acme/base/manifests/1',
        ], $asked);
    }

    public function test_a_name_on_another_registry_is_not_asked(): void
    {
        $asked = false;
        $lookup = new PublicImageLookup(function () use (&$asked): int {
            $asked = true;

            return 200;
        });

        $this->assertFalse($lookup->onDockerHub('ghcr.io/acme/base:1'));
        $this->assertFalse($asked);
    }

    public function test_not_found_and_unauthorized_both_mean_not_published(): void
    {
        $this->assertFalse((new PublicImageLookup(static fn (): int => 404))->onDockerHub('x:1'));
        $this->assertFalse((new PublicImageLookup(static fn (): int => 401))->onDockerHub('x:1'));
    }

    public function test_anything_else_cannot_be_told(): void
    {
        $this->assertNull((new PublicImageLookup(static fn (): ?int => null))->onDockerHub('x:1'));
        $this->assertNull((new PublicImageLookup(static fn (): int => 429))->onDockerHub('x:1'));
        $this->assertNull((new PublicImageLookup(static fn (): int => 502))->onDockerHub('x:1'));
    }
}
