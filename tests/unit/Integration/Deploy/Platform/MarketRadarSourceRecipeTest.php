<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\MarketRadarSourceRecipeFixtures;

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
    use MarketRadarSourceRecipeFixtures;

    /**
     * The recipe is found by its URL, so no detect ran and nothing
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
}
