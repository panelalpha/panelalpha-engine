<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Dind\AccountImage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_required_image_is_warmed_whatever_the_budget(): void
    {
        $plan = HostPrewarmPlan::select(
            HostPrewarmPlan::required(),
            [AccountImage::REF => 2 * 1073741824],
            availableBytes: 11 * 1073741824,
            reserveBytes: 10 * 1073741824,
            budgetBytes: 0
        );

        $this->assertSame([AccountImage::REF], array_column($plan['selected'], 'ref'));
    }

    public function test_a_required_image_already_present_is_not_warmed_again(): void
    {
        $plan = HostPrewarmPlan::select(HostPrewarmPlan::required(), [], 100 * 1073741824, 0, null, [AccountImage::REF]);

        $this->assertSame([], $plan['selected']);
        $this->assertSame('already present', $plan['skipped'][0]['reason']);
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
}
