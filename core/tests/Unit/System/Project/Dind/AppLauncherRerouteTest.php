<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Port\ComposePortScan;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After the health probe: a site routed to the lowest of one service's ports
 * moves to the one other port that served a page (Cabernet, engine#352).
 */
class AppLauncherRerouteTest extends TestCase
{
    private const LOWEST = ['reason' => ComposePortScan::CHOSEN_LOWEST, 'alternatives' => [6077]];

    /** @return list<array<string, mixed>> */
    private static function probed(?int $on5004, ?int $on6077): array
    {
        return [
            ['port' => 5004, 'status' => 'error', 'http_code' => $on5004],
            ['port' => 6077, 'status' => 'ok', 'http_code' => $on6077],
        ];
    }

    public function test_cabernet_moves_to_the_port_that_served_a_page(): void
    {
        $this->assertSame(
            ['port' => 6077, 'reason' => '5004 answered 501, 6077 answered 200'],
            AppLauncher::betterRoute(self::LOWEST, 5004, null, 5004, self::probed(501, 200))
        );
        $this->assertSame(
            ['port' => 6077, 'reason' => '5004 did not answer, 6077 answered 302'],
            AppLauncher::betterRoute(self::LOWEST, 5004, null, 5004, self::probed(null, 302))
        );
    }

    /** The re-checked verdict is about the port the site now goes to, not the first one published. */
    public function test_the_routed_port_is_probed_first(): void
    {
        $this->assertSame([6077, 5004], AppHealth::withRoutedPortFirst([5004, 6077], 6077));
        $this->assertSame([5004, 6077], AppHealth::withRoutedPortFirst([5004, 6077], 5004));
        $this->assertSame([5004, 6077], AppHealth::withRoutedPortFirst([5004, 6077], 9999));
        $this->assertSame([5004, 6077], AppHealth::withRoutedPortFirst([5004, 6077], null));
    }

    /**
     * @return iterable<string, array{0: ?array{reason: string, alternatives: list<int>}, 1: ?int, 2: ?int, 3: ?int, 4: list<array<string, mixed>>}>
     */
    public static function staysPut(): iterable
    {
        yield 'routed port serves' => [self::LOWEST, 5004, null, 5004, self::probed(200, 200)];
        yield 'no other port serves' => [self::LOWEST, 5004, null, 5004, self::probed(501, 404)];
        yield 'a preferred port' => [['reason' => ComposePortScan::CHOSEN_PREFERRED, 'alternatives' => []], 5004, null, 5004, self::probed(501, 200)];
        yield 'the healthcheck port' => [['reason' => ComposePortScan::CHOSEN_HEALTHCHECK, 'alternatives' => []], 5004, null, 5004, self::probed(501, 200)];
        yield 'a recipe port' => [self::LOWEST, 5004, 5004, 5004, self::probed(501, 200)];
        yield 'already routed elsewhere' => [self::LOWEST, 5004, null, 6077, self::probed(501, 200)];
        yield 'no choice' => [null, null, null, 5004, self::probed(501, 200)];
        yield 'two others serve' => [
            ['reason' => ComposePortScan::CHOSEN_LOWEST, 'alternatives' => [6077, 8502]],
            5004, null, 5004,
            [...self::probed(501, 200), ['port' => 8502, 'status' => 'ok', 'http_code' => 200]],
        ];
    }

    /**
     * @param array{reason: string, alternatives: list<int>}|null $choice
     * @param list<array<string, mixed>> $results
     */
    #[DataProvider('staysPut')]
    public function test_the_route_stays_where_the_rule_does_not_apply(?array $choice, ?int $primary, ?int $recipe, ?int $routed, array $results): void
    {
        $this->assertNull(AppLauncher::betterRoute($choice, $primary, $recipe, $routed, $results));
    }
}
