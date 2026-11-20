<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\ActivityWatchSourceRecipeFixtures;
use Tests\Support\TestNetwork;

/**
 * ActivityWatch is a git-submodule bundle, not an application.
 *
 * Its root carries only the Makefile that drives the submodules and a
 * pyproject.toml with `package-mode = false`; every runnable module lives in
 * an `aw-*` submodule. File detection reaches the Python platform but cannot
 * work out a start command, so the deploy is refused before it starts, and
 * no install-time change can make the root installable — it is not a project.
 *
 * The recipe therefore ships a Dockerfile that builds the web UI and installs
 * the server, which is what the project's own `make build` does. These assert
 * the recipe and the compose file it produces; only a deploy can say it
 * serves.
 */
class ActivityWatchSourceRecipeTest extends TestCase
{
    use ActivityWatchSourceRecipeFixtures;

    public function test_the_repository_url_resolves_to_the_activitywatch_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('activitywatch', $recipe->id);
        $this->assertSame('dockerfile', $recipe->strategy);
        $this->assertSame('dockerfile', $recipe->runtime);
        // aw-server's own default port.
        $this->assertSame(5600, $recipe->port);
    }

    /**
     * The path chose the recipe, so nothing may re-decide: an empty `detect`
     * keeps it out of the priority walk.
     */
    public function test_it_is_found_by_path_alone(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame([], $recipe->detect);
    }

    /**
     * The Dockerfile has to build the web UI and install the server, because
     * neither is at the repository root and nothing else in the pipeline does.
     */
    public function test_its_dockerfile_builds_the_webui_and_installs_the_server(): void
    {
        $path = SourceRecipes::defaultDirectory()
            . '/github.com/activitywatch/activitywatch/files/Dockerfile';

        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('npm run build', $contents);
        $this->assertStringContainsString('pip install', $contents);
        $this->assertStringContainsString('./aw-server', $contents);
        $this->assertStringContainsString('aw-webui', $contents);
        // The webui's vue.config.js shells out to git at build time, so the
        // builder stages a throwaway repository for it.
        $this->assertStringContainsString('git init', $contents);
        $this->assertStringContainsString('--host', $contents);
        $this->assertStringContainsString(TestNetwork::ANY, $contents);
    }

    /** A deploy records the recipe id and resolves it back on every read. */
    public function test_the_registry_resolves_the_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('activitywatch'));
    }
}
