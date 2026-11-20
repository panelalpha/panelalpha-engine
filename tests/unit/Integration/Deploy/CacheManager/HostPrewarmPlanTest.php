<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use PHPUnit\Framework\TestCase;
use Tests\Support\HostPrewarmPlanFixtures;

/**
 * The plan is whatever `config/core/prewarm.yaml` says, so these assert the
 * rules that hold whatever an operator put there — never the shipped values,
 * which the file exists to be edited.
 */
class HostPrewarmPlanTest extends TestCase
{
    use HostPrewarmPlanFixtures;

    public function test_a_runtime_version_resolves_to_the_image_the_engine_would_use(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          php:
            versions:
              - version: "8.3"
                prewarm: 100
                why: "shared base"
          go:
            versions:
              - version: "1.22"
                prewarm: 90
                why: "stock image"
        YAML);

        $catalog = HostPrewarmPlan::available();

        // PHP declares an image.build, so warming it means building our tag.
        $this->assertSame(PhpBaseImage::tag('php:8.3-apache-bookworm'), $catalog[0]['ref']);
        $this->assertSame(HostPrewarmPlan::KIND_BUILD, $catalog[0]['kind']);

        // Go declares none, so warming it is a plain pull of the stock tag.
        $this->assertSame('golang:1.22-alpine', $catalog[1]['ref']);
        $this->assertSame(HostPrewarmPlan::KIND_PULL, $catalog[1]['kind']);
    }

    /**
     * The whole point of listing versions one per line: an operator warms the
     * minors their accounts actually pin and pays nothing for the rest.
     */
    public function test_a_version_left_out_of_the_config_is_not_warmed(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          php:
            versions:
              - version: "8.5"
                prewarm: 100
                why: "the only minor this host serves"
        YAML);

        $refs = array_map(static fn (array $i): string => $i['ref'], HostPrewarmPlan::available());

        $this->assertSame([PhpBaseImage::tag('php:8.5-apache-bookworm')], $refs);
        $this->assertStringNotContainsString('8.1', $refs[0]);
    }

    public function test_an_entry_naming_something_the_catalogue_cannot_resolve_is_dropped(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          cobol:
            versions:
              - version: "85"
                prewarm: 100
                why: "not a runtime"
          php:
            versions:
              - version: "9.9"
                prewarm: 90
                why: "a minor the runtimes block does not describe"
        extras:
          - image: "-rf"
            runtime: static
            prewarm: 80
            why: "docker would read this as a flag"
          - image: "nginx:alpine"
            runtime: static
            prewarm: 70
            why: "the one good line"
        YAML);

        $refs = array_map(static fn (array $i): string => $i['ref'], HostPrewarmPlan::available());

        $this->assertSame(['nginx:alpine'], $refs);
    }

    /**
     * A build has no manifest to measure until it exists, and it is the one
     * item worth minutes rather than seconds — so an unmeasured image is
     * warmed rather than refused. The caller's free-space check is what stops
     * the run.
     */
    public function test_an_unmeasured_image_is_warmed_without_being_charged_for(): void
    {
        $this->withConfig(<<<'YAML'
        runtimes:
          php:
            versions:
              - version: "8.3"
                prewarm: 100
                why: "no manifest until it is built"
        extras:
          - image: "nginx:alpine"
            runtime: static
            prewarm: 90
            why: "measurable"
        YAML);

        $catalog = HostPrewarmPlan::available();
        $plan = HostPrewarmPlan::select(
            $catalog,
            ['nginx:alpine' => 100 * 1048576],
            availableBytes: 100 * 1073741824,
            reserveBytes: 0,
            budgetBytes: 200 * 1048576
        );

        $refs = array_map(static fn (array $i): string => $i['ref'], $plan['selected']);
        $this->assertSame([$catalog[0]['ref'], 'nginx:alpine'], $refs);
        $this->assertNull($plan['selected'][0]['bytes']);
    }

    public function test_present_images_are_skipped_and_free_their_budget(): void
    {
        $this->withConfig(<<<'YAML'
        extras:
            - image: "nginx:alpine"
              runtime: static
              prewarm: 100
              why: "already here"
        YAML);

        $plan = HostPrewarmPlan::select(
            HostPrewarmPlan::available(),
            ['nginx:alpine' => 100 * 1048576],
            100 * 1073741824,
            0,
            null,
            ['nginx:alpine']
        );

        $this->assertSame([], $plan['selected']);
        $this->assertSame('already present', $plan['skipped'][0]['reason']);
    }

    public function test_disk_limits_come_from_the_config(): void
    {
        $this->withConfig(<<<'YAML'
        host:
          reserve: 4G
          budget: 2G
        YAML);

        $this->assertSame(4 * 1073741824, HostPrewarmPlan::reserveBytes());
        $this->assertSame(2 * 1073741824, HostPrewarmPlan::budgetBytes());
    }

    public function test_a_budget_of_none_means_up_to_the_reserve(): void
    {
        $this->withConfig(<<<'YAML'
        host:
          reserve: 4G
          budget: none
        YAML);

        $this->assertNull(HostPrewarmPlan::budgetBytes());
    }

    private function withConfig(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'images') . '.yaml';
        file_put_contents($path, $yaml);
        ImageCatalog::useConfig($path);
    }
}
