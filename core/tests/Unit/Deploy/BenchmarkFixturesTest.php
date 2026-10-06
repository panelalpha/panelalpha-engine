<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * The fixture table in `scripts/tools/benchmark-deploys.sh`.
 *
 * Its expectations are strategy names, and a typo in one is a benchmark that
 * fails against a live host after twenty minutes of deploying. Checking them
 * against the strategies the engine actually ships costs nothing and moves
 * that failure to CI.
 *
 * See AGENTS.md §9.
 */
class BenchmarkFixturesTest extends TestCase
{
    /** Platforms deliberately covered twice, so a regression shows as a pair. */
    private const MIN_PER_STRATEGY = 2;

    /**
     * @return list<array{name: string, repo: string, strategy: string}>
     */
    private function fixtures(): array
    {
        $script = dirname(__DIR__, 3) . '/../scripts/tools/benchmark-deploys.sh';
        $this->assertFileExists($script, 'the benchmark script moved');

        $contents = (string) file_get_contents($script);
        preg_match('/^FIXTURES=\((.*?)^\)/ms', $contents, $block);
        $this->assertNotEmpty($block, 'no FIXTURES block in the benchmark script');

        $rows = [];
        foreach (preg_split('/\r?\n/', $block[1]) ?: [] as $line) {
            if (preg_match('/"([^|"]+)\|([^|"]*)\|([^|"]*)\|([^|"]+)"/', $line, $m) !== 1) {
                continue;
            }
            $rows[] = ['name' => $m[1], 'repo' => $m[2], 'strategy' => $m[4]];
        }

        return $rows;
    }

    public function test_every_expectation_is_a_strategy_the_engine_ships(): void
    {
        $shipped = array_map(static fn ($m): string => $m->strategy, PlatformRegistry::all());
        $known = array_unique(array_merge($shipped, Strategies::ALL));

        foreach ($this->fixtures() as $fixture) {
            $this->assertContains(
                $fixture['strategy'],
                $known,
                "{$fixture['name']} expects '{$fixture['strategy']}', which no platform produces"
            );
        }
    }

    public function test_each_covered_strategy_has_at_least_two_fixtures(): void
    {
        $counts = array_count_values(array_column($this->fixtures(), 'strategy'));

        foreach ($counts as $strategy => $count) {
            $this->assertGreaterThanOrEqual(
                self::MIN_PER_STRATEGY,
                $count,
                "'{$strategy}' has {$count} fixture(s); one repo behaving oddly is not a regression"
            );
        }
    }

    public function test_the_account_names_are_valid_usernames(): void
    {
        // UserStoreRequest: lowercase alphanumeric, starts with a letter, 3-15
        // characters. A name the API rejects wastes a whole benchmark run.
        foreach ($this->fixtures() as $fixture) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9]{2,14}$/',
                $fixture['name'],
                "'{$fixture['name']}' is not a username the API will accept"
            );
        }
    }

    public function test_the_set_covers_the_strategies_that_differ_under_caching(): void
    {
        $covered = array_unique(array_column($this->fixtures(), 'strategy'));

        // Each of these takes a different path through the build and the
        // caches: no build at all, the repo's own definition, a generated
        // Dockerfile. Railpack is not among them: every runtime it recognises
        // has a platform now, and a recipe pinned to it holds for one deploy,
        // so a warm rebuild would detect again and measure another strategy.
        foreach ([Strategies::STATIC, Strategies::COMPOSE, Strategies::DOCKERFILE,
                  Strategies::PHP, Strategies::LARAVEL, Strategies::NODE] as $strategy) {
            $this->assertContains($strategy, $covered, "no fixture exercises '{$strategy}'");
        }
    }
}
