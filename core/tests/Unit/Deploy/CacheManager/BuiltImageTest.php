<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use PHPUnit\Framework\TestCase;

/**
 * Recognising our own images, and what follows from getting it wrong.
 *
 * Nothing publishes `panelalpha/*`. A tag the engine does not recognise as its
 * own is handed to the pull path, 404s on Docker Hub, and ends at a pull
 * inside the account's nested NAT — 25 minutes and a dead deploy. So these are
 * not shape tests; each one stands for a failure mode.
 */
class BuiltImageTest extends TestCase
{
    private function phpBase(): string
    {
        return (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
    }

    private function phpVariant(): string
    {
        return (string) PhpBaseImage::tag('php:8.3-apache-bookworm', ['imagick']);
    }

    private function rubyBase(): string
    {
        return (string) RubyBaseImage::tag('ruby:3.3-slim-bookworm', ['libpq-dev']);
    }

    /**
     * The bug this class was written for.
     *
     * Ruby's mounted path freezes `panelalpha/ruby:…` as the deploy image, and
     * the only thing that decided "ours" was PhpBaseImage's own prefix — so
     * Ruby's base went to the registry ladder every time.
     */
    public function test_a_ruby_base_is_ours(): void
    {
        $tag = $this->rubyBase();

        $this->assertSame('ruby', BuiltImage::runtimeFor($tag));
        $this->assertTrue(BuiltImage::isOurs($tag));
        $this->assertSame('ruby:3.3-slim-bookworm', BuiltImage::sourceImage($tag));
    }

    public function test_a_php_base_is_ours(): void
    {
        $tag = $this->phpBase();

        $this->assertSame('php', BuiltImage::runtimeFor($tag));
        $this->assertTrue(BuiltImage::isOurs($tag));
        $this->assertSame('php:8.3-apache-bookworm', BuiltImage::sourceImage($tag));
    }

    public function test_upstream_and_foreign_images_are_not_ours(): void
    {
        foreach (['php:8.3-apache-bookworm', 'ruby:3.3-slim-bookworm', 'mysql:8.4', 'nginx:alpine'] as $ref) {
            $this->assertFalse(BuiltImage::isOurs($ref), "{$ref} must go down the pull path");
            $this->assertNull(BuiltImage::sourceImage($ref));
        }
    }

    /**
     * Our repository, but not a tag we wrote — no recipe fingerprint. Claiming
     * it would mean offering to rebuild something we cannot describe.
     */
    public function test_a_tag_without_a_fingerprint_is_not_one_of_ours(): void
    {
        $this->assertFalse(BuiltImage::isOurs(PhpBaseImage::repository() . ':8.3-apache-bookworm'));
        $this->assertNull(BuiltImage::sourceImage(PhpBaseImage::repository() . ':latest'));
        $this->assertNull(BuiltImage::sourceImage(PhpBaseImage::repository()));
    }

    // -------------------------------------------------------------------------
    // Variants: ours, reclaimable, and deliberately not rebuildable
    // -------------------------------------------------------------------------

    public function test_a_variant_is_ours_and_names_the_same_upstream(): void
    {
        $variant = $this->phpVariant();

        $this->assertTrue(BuiltImage::isOurs($variant));
        $this->assertTrue(BuiltImage::isVariant($variant));
        $this->assertSame('php:8.3-apache-bookworm', BuiltImage::sourceImage($variant));
    }

    /**
     * The `-x…` suffix hashes the baked set, and a hash does not go backwards.
     * Rebuilding from the tag alone would produce the plain base wearing a name
     * that promises imagick — an image that installs cleanly and 500s on the
     * first request that needs the extension.
     */
    public function test_a_variant_is_never_rebuildable_from_its_tag_alone(): void
    {
        $this->assertFalse(BuiltImage::isRebuildableFromTag($this->phpVariant()));
        $this->assertTrue(BuiltImage::isRebuildableFromTag($this->phpBase()));
    }

    public function test_the_plain_base_and_its_variant_share_a_fingerprint(): void
    {
        $this->assertSame(
            BuiltImage::fingerprintOf($this->phpBase()),
            BuiltImage::fingerprintOf($this->phpVariant()),
            'a variant is the same recipe plus extras, so it tracks the same recipe fingerprint'
        );
    }

    public function test_an_upstream_tag_containing_pa_is_split_on_the_last_one(): void
    {
        // Nothing forbids an official tag from containing "-pa". Anchoring on
        // the first occurrence would cut the upstream tag in half and name an
        // image that does not exist.
        $tag = PhpBaseImage::repository() . ':8.3-pale-moon-pa' . PhpBaseImage::fingerprint();

        $this->assertSame('php:8.3-pale-moon', BuiltImage::sourceImage($tag));
    }

    // -------------------------------------------------------------------------
    // Retention
    // -------------------------------------------------------------------------

    /**
     * The bug: `…-paFP-xEX` does not end in `-paFP`, so the old suffix check
     * called every current variant superseded. Each prewarm run deleted the
     * imagick base, and each account wanting imagick then paid the ~36s
     * compile the variant exists to prevent — until the next run deleted the
     * rebuilt one again.
     */
    public function test_a_current_variant_is_not_stale(): void
    {
        $stale = HostPrewarmPlan::staleBuiltImages([$this->phpBase(), $this->phpVariant()]);

        $this->assertSame([], $stale);
    }

    public function test_a_superseded_base_is_stale(): void
    {
        $old = PhpBaseImage::repository() . ':8.3-apache-bookworm-padeadbeef';

        $this->assertSame([$old], HostPrewarmPlan::staleBuiltImages([$this->phpBase(), $old]));
    }

    public function test_a_superseded_variant_is_stale(): void
    {
        $old = PhpBaseImage::repository() . ':8.3-apache-bookworm-padeadbeef-xf4f426f8';

        $this->assertSame([$old], HostPrewarmPlan::staleBuiltImages([$old]));
    }

    /**
     * Ruby's fingerprint is over its apt package set, which is per-image rather
     * than per-recipe — there is no single current value, so nothing may call a
     * Ruby base superseded. "Cannot judge" has to mean keep: this decides what
     * gets deleted, and the cost of a wrong call is a rebuild nobody asked for.
     */
    public function test_ruby_bases_are_never_called_stale(): void
    {
        $this->assertSame([], HostPrewarmPlan::staleBuiltImages([$this->rubyBase()]));
        $this->assertNull(BuiltImage::currentFingerprint('ruby'));
    }

    public function test_images_we_did_not_build_are_never_touched(): void
    {
        $this->assertSame(
            [],
            HostPrewarmPlan::staleBuiltImages(['nginx:alpine', 'php:8.1-apache-bookworm', 'mysql:8.4'])
        );
    }

    // -------------------------------------------------------------------------
    // Reclaim under pressure
    // -------------------------------------------------------------------------

    public function test_nothing_is_reclaimed_while_the_reserve_is_met(): void
    {
        $sizes = [$this->phpBase() => 780 * 1048576];

        $this->assertSame(
            [],
            HostPrewarmPlan::reclaimableBuiltImages($sizes, 20_000_000_000, 10_000_000_000)
        );
    }

    /**
     * Under pressure the unranked image goes first: nothing in the warm plan
     * asked for it, so it is a variant built on demand for one account.
     */
    public function test_the_least_valuable_image_is_given_up_first(): void
    {
        $base = $this->phpBase();
        $variant = $this->phpVariant();
        $sizes = [$base => 800 * 1048576, $variant => 800 * 1048576];

        $drop = HostPrewarmPlan::reclaimableBuiltImages(
            $sizes,
            9_500_000_000,
            10_000_000_000,
            HostPrewarmPlan::available()
        );

        $this->assertSame([$variant], $drop, 'the catalogued base is worth more than an on-demand variant');
    }

    public function test_dropping_stops_once_the_shortfall_is_covered(): void
    {
        $sizes = [
            $this->phpVariant() => 400 * 1048576,
            $this->rubyBase() => 400 * 1048576,
            $this->phpBase() => 400 * 1048576,
        ];

        // 300MB short, and every candidate is 400MB: one is enough.
        $drop = HostPrewarmPlan::reclaimableBuiltImages(
            $sizes,
            10_000_000_000 - 300 * 1048576,
            10_000_000_000,
            HostPrewarmPlan::available()
        );

        $this->assertCount(1, $drop);
    }

    /**
     * A pulled upstream image belongs to whoever published it, and deleting it
     * only makes the next deploy pull it again from a registry that still has
     * it. The space is real; the trade is worse.
     */
    public function test_only_images_we_built_are_ever_reclaimed(): void
    {
        $sizes = ['nginx:alpine' => 5_000_000_000, 'node:20-bookworm-slim' => 5_000_000_000];

        $this->assertSame(
            [],
            HostPrewarmPlan::reclaimableBuiltImages($sizes, 0, 10_000_000_000, HostPrewarmPlan::available())
        );
    }
}
