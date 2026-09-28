<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * Market Radar answers 200 on a page that says nothing about whether it works.
 *
 * The repository is a deployment repository — its `docker-compose.yml` brings
 * its own `mariadb:11` and its own `Dockerfile` builds both halves — so
 * detection picking `compose` is correct and the recipe changes nothing about
 * how it is built. What it adds is a way to be checked.
 *
 * `/` is an SPA shell: a title, an empty `#root` and two script tags, identical
 * whether the database is up or gone. Every baseline check passed on a deploy
 * whose MariaDB was unreachable, which is the deploy this recipe was written
 * after. The application's own answer is at `/api/health`.
 *
 * What is asserted here is the recipe and the verdict it produces. A deploy is
 * the only thing that can say the application installs.
 */
class MarketRadarSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/vvolv/market-radar';

    /** The shell `/` serves, which is all any check on `/` can see. */
    private const SHELL = <<<'HTML'
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="UTF-8" />
            <title>Market Radar</title>
            <script type="module" crossorigin src="/assets/index-D1OC2HFn.js"></script>
            <link rel="stylesheet" crossorigin href="/assets/index-5OEtw5j0.css">
          </head>
          <body>
            <div id="root"></div>
          </body>
        </html>
        HTML;

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
    }

    protected function tearDown(): void
    {
        CheckRegistry::flush();
        parent::tearDown();
    }

    public function test_the_repository_url_resolves_to_the_market_radar_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL); // @phpstan-ignore-line — resolves through the shipped tree

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        // The strategy is inherited from the compose platform, and the recipe
        // says nothing about building: the repository ships the compose file
        // the engine would run. The id is its own, which is a separate matter
        // and deliberately not inherited -- see the test below.
        $this->assertSame('compose', $recipe->strategy);
        $this->assertSame('compose', $recipe->runtime);
    }

    /** The check the recipe adds, named by the failure rather than the endpoint. */
    public function test_the_recipe_declares_its_own_health_check(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame(['_baseline/api-health'], $recipe->checks);
    }

    /**
     * The recipe answers to its own id, not to the platform it extends.
     *
     * `extends: compose` with no `id` would make it answer to `compose`, which
     * is the shipped compose *platform*'s id too — and everything downstream
     * resolves a persisted `deploy_platform` through `PlatformRegistry`, which
     * walks the shipped manifests first. The recipe's checks then resolved to
     * the platform's empty `check:` list and were silently never asked.
     */
    public function test_it_answers_to_its_own_id_rather_than_the_platform_it_extends(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('market-radar', $recipe->id);
        $this->assertNotSame(
            'compose',
            $recipe->id,
            'extending the compose platform without an id is what hid the check'
        );
    }

    /**
     * Stating an id must not change the strategy.
     *
     * `compose.yaml` carries no `strategy:` key — its id *is* its strategy,
     * which is a real shape the shipped tree uses for `compose` and `dockerfile`
     * alike. So a recipe with its own id that extends such a base inherits no
     * strategy, and `PlatformManifest` defaults it to the recipe's own id:
     * this produced `strategy: market-radar`, which no generator recognises.
     * The base's id is what the base meant by omitting the key.
     */
    public function test_the_strategy_is_the_base_platforms_even_though_the_id_is_its_own(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $this->assertSame('compose', $recipe->strategy);
    }

    /**
     * The path the deploy actually takes: a persisted `deploy_platform` is
     * resolved to its manifest to find the checks it declared.
     *
     * This is the regression guard for `declaredChecks()`, which used to walk
     * `PlatformRegistry::all()` — a list that stops at the shipped trees — and
     * so could never reach a recipe keyed by repository URL.
     */
    public function test_the_recipe_id_resolves_to_its_checks_the_way_the_deploy_resolves_them(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $resolved = \App\Lib\Deploy\Platform\PlatformRegistry::find($recipe->id);

        $this->assertNotNull($resolved, 'the recipe id does not resolve through the registry');
        $this->assertSame(['_baseline/api-health'], $resolved->checks);

        // And the walk that used to be used cannot see it, which is why the
        // resolution had to change rather than the recipe.
        $viaWalk = array_filter(
            \App\Lib\Deploy\Platform\PlatformRegistry::all(),
            static fn ($manifest): bool => $manifest->id === $recipe->id
        );
        $this->assertSame(
            [],
            array_values(array_map(static fn ($m): array => $m->checks, $viaWalk))
        );
    }

    /** Its own check file is loaded, merged onto the shipped baseline. */
    public function test_the_shipped_baseline_and_the_recipe_check_are_both_asked(): void
    {
        $checksDirectory = SourceRecipes::checksDirectory(
            SourceRecipes::directoryFor(self::URL) ?? ''
        );
        $this->assertNotNull($checksDirectory, 'the recipe ships no checks/ directory');

        $references = array_map(
            static fn ($check): string => $check->reference(),
            CheckRegistry::allWithDirectory($checksDirectory)['_baseline'] ?? []
        );

        $this->assertContains('_baseline/api-health', $references);
        // Merged, not replaced: the baseline the engine ships is still there.
        $this->assertContains('_baseline/not-placeholder', $references);
        $this->assertContains('_baseline/no-server-error', $references);
    }

    /**
     * The whole reason the recipe exists.
     *
     * The shell passes on `/` — it is not a placeholder, not a default page, not
     * a directory listing, and answers 200 — and the same deploy fails on the
     * endpoint that knows.
     */
    public function test_a_shell_at_the_root_is_healthy_and_a_dead_database_is_not(): void
    {
        $healthy = $this->report([
            '/' => new ProbedResponse(200, self::SHELL, 'http://127.0.0.1:4000/'),
            '/api/health' => new ProbedResponse(200, '{"status":"ok","database":"connected"}', 'http://127.0.0.1:4000/api/health'),
        ]);

        $this->assertSame(CheckRunner::SERVING_OK, $healthy['serving']);
        $this->assertSame('pass', $this->check($healthy, 'api-health')['status']);

        $broken = $this->report([
            '/' => new ProbedResponse(200, self::SHELL, 'http://127.0.0.1:4000/'),
            // What the backend answers when `SELECT 1` fails: a 500, and the
            // reason in the body.
            '/api/health' => new ProbedResponse(500, '{"status":"error","database":"unreachable"}', 'http://127.0.0.1:4000/api/health'),
        ]);

        $failed = $this->check($broken, 'api-health');
        $this->assertSame('fail', $failed['status']);
        $this->assertSame('error', $failed['severity']);
        // The report says what is broken rather than "the site is down", which
        // is the difference between a page nobody can use and a service that
        // is not answering.
        $this->assertSame('database_error', $broken['serving']);
    }

    /**
     * A 200 that lies is the case a status-only check cannot see, and the one
     * this application produces when the failure is caught rather than thrown.
     */
    public function test_a_200_reporting_an_unreachable_database_still_fails(): void
    {
        $report = $this->report([
            '/' => new ProbedResponse(200, self::SHELL, 'http://127.0.0.1:4000/'),
            '/api/health' => new ProbedResponse(200, '{"status":"error","database":"unreachable"}', 'http://127.0.0.1:4000/api/health'),
        ]);

        $this->assertSame('fail', $this->check($report, 'api-health')['status']);
    }

    /**
     * engine#221: the recipe is found by its URL, so no detect ran and nothing
     * told the decision where the compose file is. The deployability check then
     * refused a checkout that has one.
     */
    public function test_a_checkout_with_its_compose_file_is_deployable_through_the_recipe(): void
    {
        $project = sys_get_temp_dir() . '/pa-market-radar-' . bin2hex(random_bytes(6));
        mkdir($project, 0777, true);
        file_put_contents(
            $project . '/docker-compose.yml',
            "services:\n  app:\n    image: nginx:1.27\n    ports:\n      - \"8080:80\"\n"
        );

        try {
            SourceRecipes::flush();
            $decision = DetectProjectStrategy::detect($project, self::URL);

            $this->assertSame('market-radar', $decision['platform']);
            $this->assertSame('compose', $decision['strategy']);
            $this->assertSame($project . '/docker-compose.yml', $decision['compose_path']);
            DeployabilityCheck::assert($decision, $project);
        } finally {
            @unlink($project . '/docker-compose.yml');
            @rmdir($project);
        }
    }

    /**
     * @param array<string, ProbedResponse> $responses
     * @return array<string, mixed>
     */
    private function report(array $responses): array
    {
        $directory = SourceRecipes::checksDirectory(SourceRecipes::directoryFor(self::URL) ?? '');

        return CheckRunner::forWithDirectory('compose', [], $directory)
            ->runByPath($responses, sys_get_temp_dir());
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function check(array $report, string $id): array
    {
        foreach ($report['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }

        $this->fail("check '{$id}' was not asked");
    }
}
