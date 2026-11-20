<?php

namespace Tests\Support;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;

trait RuntimeImageCatalogFallbackFixtures
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        RuntimeImageCatalog::useConfig(null);
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];

        parent::tearDown();
    }

    /**
     * Resolution survives a bad config; the built image does not exist under
     * one. Both halves are asserted together because leaving only the first
     * true is the dangerous state: an image built under a name this file no
     * longer describes is fetched from a registry that never published it.
     */
    private function assertPhpStillDeploysWithoutABase(string $why): void
    {
        $this->assertSame(PhpRuntime::MINORS, PhpRuntime::minors(), $why);
        $this->assertSame(PhpRuntime::DEFAULT_MINOR, PhpRuntime::defaultMinor(), $why);
        $this->assertSame(
            'php:8.3-' . PhpRuntime::IMAGE_VARIANT,
            PhpRuntime::imageTag('8.3'),
            $why
        );
        $this->assertNull(PhpBaseImage::repository(), $why);
        $this->assertNull(PhpBaseImage::stubName(), $why);
        $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm'), $why);
    }
}
