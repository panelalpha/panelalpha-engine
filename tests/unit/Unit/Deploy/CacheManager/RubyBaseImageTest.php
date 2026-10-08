<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\RubyBaseImage;
use PHPUnit\Framework\TestCase;

class RubyBaseImageTest extends TestCase
{
    public function test_tag_is_derived_from_the_official_ruby_tag(): void
    {
        $tag = (string) RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['git', 'build-essential']);

        $this->assertStringStartsWith(RubyBaseImage::repository() . ':3.3.6-slim-bookworm-pa', $tag);
        $this->assertMatchesRegularExpression('/-pa[0-9a-f]{8}$/', $tag);
    }

    /**
     * A Postgres app and a MySQL app must not be served each other's base.
     */
    public function test_a_different_package_set_is_a_different_image(): void
    {
        $pg = RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['git', 'libpq-dev']);
        $mysql = RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['git', 'default-libmysqlclient-dev']);

        $this->assertNotSame($pg, $mysql);
    }

    public function test_tag_ignores_order_case_and_duplicates(): void
    {
        $this->assertSame(
            RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['git', 'curl']),
            RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['CURL', 'git', 'git'])
        );
    }

    public function test_source_image_round_trips_the_tag(): void
    {
        $tag = (string) RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', ['git']);

        $this->assertSame('ruby:3.3.6-slim-bookworm', RubyBaseImage::sourceImage($tag));
        $this->assertNull(RubyBaseImage::sourceImage('ruby:3.3.6-slim-bookworm'));
        $this->assertNull(RubyBaseImage::sourceImage('php:8.3-cli-bookworm'));
    }

    public function test_no_tag_for_images_we_do_not_build(): void
    {
        $this->assertNull(RubyBaseImage::tag('ghcr.io/acme/ruby:3.3', ['git']));
        $this->assertNull(RubyBaseImage::tag('ruby:3.3-slim AS base', ['git']));
        $this->assertNull(RubyBaseImage::tag('', ['git']));
    }

    public function test_no_tag_without_packages_to_bake(): void
    {
        $this->assertNull(RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', []));
    }

    public function test_an_unusually_long_package_set_is_not_worth_a_host_image(): void
    {
        $many = ['a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8', 'i9'];

        $this->assertGreaterThan(RubyBaseImage::MAX_PACKAGES, count($many));
        $this->assertNull(RubyBaseImage::tag('ruby:3.3.6-slim-bookworm', $many));
    }

    public function test_package_names_that_are_not_package_names_are_dropped(): void
    {
        $this->assertSame(['git'], RubyBaseImage::normalizePackages(['git', '; rm -rf /', '--flag', '']));
    }

    public function test_dockerfile_installs_exactly_the_named_packages(): void
    {
        $dockerfile = RubyBaseImage::dockerfile('ruby:3.3.6-slim-bookworm', ['libpq-dev', 'git']);

        $this->assertStringContainsString('FROM ruby:3.3.6-slim-bookworm', $dockerfile);
        $this->assertStringContainsString('install -y --no-install-recommends git libpq-dev', $dockerfile);
        $this->assertStringContainsString('panelalpha.base="ruby"', $dockerfile);
    }
}
