<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plan is whatever `config/core/prewarm.yaml` says, so these assert the
 * rules that hold whatever an operator put there — never the shipped values,
 * which the file exists to be edited.
 */
class HostPrewarmPlanTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageCatalog::useConfig(null);
        parent::tearDown();
    }

    public function test_catalog_is_ordered_by_declared_prewarm_priority(): void
    {
        $priorities = array_map(
            static fn (array $i): int => $i['prewarm'],
            HostPrewarmPlan::available()
        );

        $sorted = $priorities;
        rsort($sorted);
        $this->assertSame($sorted, $priorities);
        $this->assertNotEmpty($priorities);
    }

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

    public function test_runtime_filter_narrows_the_catalog(): void
    {
        $runtimes = array_unique(array_map(
            static fn (array $i): string => $i['runtime'],
            HostPrewarmPlan::available(['python', 'go'])
        ));
        sort($runtimes);

        $this->assertSame(['go', 'python'], $runtimes);
    }

    public function test_reserve_is_never_spent(): void
    {
        // 12G free with a 10G reserve leaves 2G, whatever the budget says.
        $catalog = HostPrewarmPlan::available();
        $sizes = array_fill_keys(
            array_map(static fn (array $i): string => $i['ref'], $catalog),
            1073741824
        );

        $plan = HostPrewarmPlan::select(
            $catalog,
            $sizes,
            availableBytes: 12 * 1073741824,
            reserveBytes: 10 * 1073741824,
            budgetBytes: 100 * 1073741824
        );

        $spent = array_sum(array_map(static fn (array $i): int => $i['bytes'], $plan['selected']));

        $this->assertSame(2 * 1073741824, $plan['spendable']);
        $this->assertLessThanOrEqual(2 * 1073741824, $spent);
    }

    public function test_a_full_disk_warms_nothing_rather_than_filling_it(): void
    {
        $plan = HostPrewarmPlan::select(
            HostPrewarmPlan::available(),
            [],
            availableBytes: 5 * 1073741824,
            reserveBytes: 10 * 1073741824,
            budgetBytes: null
        );

        $this->assertSame(0, $plan['spendable']);
        $this->assertSame([], $plan['selected']);
        $this->assertNotEmpty($plan['skipped']);
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

    public function test_an_unreadable_config_falls_back_rather_than_warming_blind(): void
    {
        ImageCatalog::useConfig('/nonexistent/images.yaml');

        $this->assertSame([], HostPrewarmPlan::available());
        $this->assertSame(HostPrewarmPlan::FALLBACK_RESERVE_BYTES, HostPrewarmPlan::reserveBytes());
        $this->assertSame(HostPrewarmPlan::FALLBACK_BUDGET_BYTES, HostPrewarmPlan::budgetBytes());
    }

    public function test_finds_base_images_left_by_an_older_recipe(): void
    {
        $current = PhpBaseImage::tag('php:8.4-cli-bookworm');
        $this->assertIsString($current);

        $stale = HostPrewarmPlan::staleBuiltImages([
            $current,
            // The hashed tags this scheme replaced still parse, so they are
            // reclaimed rather than left on the host forever.
            'panelalpha/php:8.4-cli-bookworm-padeadbeef',
            'panelalpha/php:8.2-cli-bookworm-pa20200101',
            'node:22-bookworm-slim',
        ]);

        $this->assertSame(
            ['panelalpha/php:8.4-cli-bookworm-padeadbeef', 'panelalpha/php:8.2-cli-bookworm-pa20200101'],
            $stale
        );
    }

    public function test_only_selected_ids_are_warmed(): void
    {
        $plan = HostPrewarmPlan::catalog([], ['php:8.3', 'composer', 'not-in-the-catalogue']);

        $this->assertSame(['php:8.3', 'composer'], array_column($plan, 'id'));
    }

    /** No Laravel config here, so nothing selected: a host warms nothing by default. */
    public function test_nothing_is_warmed_unless_selected(): void
    {
        $this->assertSame([], HostPrewarmPlan::selected());
        $this->assertSame([], HostPrewarmPlan::catalog());
        $this->assertNotSame([], HostPrewarmPlan::available());
    }

    public function test_parses_a_selection(): void
    {
        $this->assertSame(['php:8.1', 'composer'], HostPrewarmPlan::parseList(' php:8.1, ,composer,php:8.1 '));
        $this->assertSame([], HostPrewarmPlan::parseList(''));
    }

    #[DataProvider('byteProvider')]
    public function test_parses_human_byte_sizes(string $input, int $expected): void
    {
        $this->assertSame($expected, HostPrewarmPlan::parseBytes($input));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function byteProvider(): array
    {
        return [
            'gigabytes' => ['6G', 6442450944],
            'megabytes' => ['512M', 536870912],
            'suffixed' => ['2GB', 2147483648],
            'plain' => ['1048576', 1048576],
        ];
    }

    public function test_rejects_a_size_it_cannot_parse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HostPrewarmPlan::parseBytes('plenty');
    }

    private function withConfig(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'images') . '.yaml';
        file_put_contents($path, $yaml);
        ImageCatalog::useConfig($path);
    }
}
