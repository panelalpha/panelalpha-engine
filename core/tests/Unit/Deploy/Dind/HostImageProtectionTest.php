<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\Dind\DindAccountCleanup;
use PHPUnit\Framework\TestCase;

/**
 * What deleting an account is allowed to take off the host.
 *
 * The host image store is a shared cache, and the rule for most of it is
 * "keep what somebody still declares" — right for a sidecar pulled for one
 * project, wrong for an image the engine builds. Those are host
 * infrastructure: nothing publishes them, they cost 30–150s to rebuild, and
 * `system:image:prewarm` exists to have them sitting there before anyone asks.
 *
 * Deleting the last Ruby account used to remove
 * `panelalpha/ruby:3.3-slim-bookworm-pa0c53db49`, which the next Ruby deploy
 * then had to rebuild.
 */
class HostImageProtectionTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function ourBases(): array
    {
        return [
            (string) PhpBaseImage::tag('php:8.3-apache-bookworm'),
            (string) PhpBaseImage::tag('php:8.3-apache-bookworm', ['imagick']),
            (string) RubyBaseImage::tag('ruby:3.3-slim-bookworm', ['libpq-dev']),
            (string) PythonBaseImage::tag('python:3.12-slim', ['libpq-dev']),
        ];
    }

    /**
     * The regression. Only `panelalpha/php:` was protected by prefix, so Ruby's
     * and Python's bases were treated as this account's sidecars.
     */
    public function test_no_image_the_engine_builds_is_ever_removed(): void
    {
        $remove = DindAccountCleanup::hostSidecarRefsToRemove($this->ourBases(), []);

        $this->assertSame([], $remove, 'a shared base the engine builds is host infrastructure');
    }

    public function test_that_holds_even_when_no_other_account_references_them(): void
    {
        // The empty keep-list is the case that bit: last account of its
        // language, nothing else declares the image, and it was reaped.
        foreach ($this->ourBases() as $base) {
            $this->assertTrue(
                DindAccountCleanup::isProtectedHostImage($base),
                "{$base} must survive the deletion of the last account using it"
            );
        }
    }

    public function test_the_engines_own_published_images_are_protected(): void
    {
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('ghcr.io/panelalpha/engine-core:20260824'));
    }

    /**
     * The other half: this is still a cleanup, and an image pulled for one
     * project's sidecar should not outlive it.
     */
    public function test_a_one_off_sidecar_image_is_still_removed(): void
    {
        $remove = DindAccountCleanup::hostSidecarRefsToRemove(
            ['opensearchproject/opensearch:2', 'typesense/typesense:27.1'],
            []
        );

        $this->assertSame(['opensearchproject/opensearch:2', 'typesense/typesense:27.1'], $remove);
    }

    public function test_an_image_another_account_still_declares_is_kept(): void
    {
        $remove = DindAccountCleanup::hostSidecarRefsToRemove(
            ['opensearchproject/opensearch:2', 'mysql:8.4'],
            ['mysql:8.4']
        );

        $this->assertSame(['opensearchproject/opensearch:2'], $remove);
    }

    /**
     * The prewarm catalogue is what an operator paid disk for deliberately.
     */
    public function test_prewarmed_images_are_kept(): void
    {
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('nginx:alpine'));
        $this->assertTrue(DindAccountCleanup::isProtectedHostImage('node:20-bookworm-slim'));
    }
}
