<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use PHPUnit\Framework\TestCase;

/**
 * How the prewarm report prints sizes, what it does with refs it cannot read, and a variant tag given no extensions.
 */
class HostPrewarmPlanFormatTest extends TestCase
{
    public function test_sizes_are_printed_in_the_largest_unit_that_fits(): void
    {
        $this->assertSame('?', HostPrewarmPlan::formatBytes(null));
        $this->assertSame('512B', HostPrewarmPlan::formatBytes(512));
        $this->assertSame('5M', HostPrewarmPlan::formatBytes(5 * 1048576));
        $this->assertSame('1.5G', HostPrewarmPlan::formatBytes(1610612736));
    }

    public function test_refs_that_are_not_strings_or_not_ours_are_never_stale(): void
    {
        $this->assertSame([], HostPrewarmPlan::staleBuiltImages([42, null, 'redis:7']));
    }

    public function test_a_variant_tag_without_its_extensions_has_no_dockerfile(): void
    {
        $variant = (string) PhpBaseImage::tag('php:8.3-apache-bookworm', ['imagick']);

        $this->assertNull(BuiltImage::dockerfile($variant));
        $this->assertNull(BuiltImage::dockerfile('redis:7'));
        $this->assertNotNull(BuiltImage::dockerfile($variant, ['imagick']));
    }

    public function test_only_php_has_one_current_fingerprint(): void
    {
        $this->assertSame(PhpBaseImage::fingerprint(), BuiltImage::currentFingerprint('php'));
        $this->assertNull(BuiltImage::currentFingerprint('ruby'));
    }
}
