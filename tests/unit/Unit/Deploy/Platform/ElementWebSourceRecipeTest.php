<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\ElementWebSourceRecipeFixtures;

/**
 * Element Web is an nx + pnpm monorepo whose application ships its own
 * Dockerfile under apps/web/ and whose repository root has no build or start
 * script at all — only the workspace manifests.
 *
 * With nothing at the root for the file rules to recognise, detection fell
 * through to Railpack, which built a container with no command that either
 * compiled the app or served it: it exited 0 immediately and restart-looped.
 *
 * The repository's own docker-bake.hcl says how the authors build it —
 * `dockerfile = "apps/web/Dockerfile"`, `context = "."`, `target =
 * "element_web"` — and the Dockerfile says the same thing in a comment
 * ("Context must be the root of the monorepo"). The engine's compose writer
 * already sets `context: .` and names the Dockerfile relative to it, so the
 * recipe only has to state the two fields a source recipe is applied without
 * probing for.
 *
 * What is asserted here is the recipe and the compose file it produces — only
 * a deploy can say Element Web installs.
 */
class ElementWebSourceRecipeTest extends TestCase
{
    use ElementWebSourceRecipeFixtures;

    public function test_the_repository_url_resolves_to_the_element_web_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('element-web', $recipe->id);
        $this->assertSame('dockerfile', $recipe->strategy);
        $this->assertSame('dockerfile', $recipe->runtime);
        // The port the Dockerfile gives nginx (ELEMENT_WEB_PORT); it has no
        // EXPOSE of its own, so nothing else could have found it.
        $this->assertSame(80, $recipe->port);
    }

    /**
     * The path chose this recipe, so nothing should re-decide: an empty
     * `detect` is what keeps it out of the priority walk.
     */
    public function test_it_is_found_by_path_alone(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame([], $recipe->detect);
    }

    /**
     * A deploy records the recipe id and resolves the manifest back through
     * the registry on every later read; the id has to keep answering.
     */
    public function test_the_registry_resolves_the_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('element-web'));
    }
}
