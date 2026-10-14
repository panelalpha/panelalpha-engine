<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
    private const URL = 'https://github.com/element-hq/element-web';

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
     * The Dockerfile lives beside the application, not at the repository
     * root, and a source recipe is applied without running the dockerfile
     * probe — so the recipe has to state it or the deployability check fails
     * with "Dockerfile strategy selected but Dockerfile is missing."
     */
    public function test_it_names_the_monorepo_dockerfile(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('apps/web/Dockerfile', $recipe->describe($this->context())['dockerfile']);
    }

    /**
     * The compose file the dockerfile strategy writes: the repository root as
     * the build context — which is what the Dockerfile's `COPY --parents` and
     * `--mount=type=bind,source=.git` require — the nested Dockerfile named
     * relative to it, and the app's port published on the host port the proxy
     * fronts.
     */
    public function test_the_generated_compose_keeps_the_root_context_and_nested_dockerfile(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $decision = $recipe->describe($this->context());
        $service = Yaml::parse(DeployCompose::dockerfile(
            $decision['dockerfile'] ?? 'Dockerfile',
            (int) $decision['port_hint'],
            $decision
        ))['services']['app'];

        // 80 becomes 8080 on the host; the proxy owns 80.
        $this->assertSame(['8080:80'], $service['ports']);
        $this->assertSame('.', $service['build']['context']);
        $this->assertSame('apps/web/Dockerfile', $service['build']['dockerfile']);
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
     * The URL outranks the files. At the repository root there is no build or
     * start script for detection to latch onto — which is why this recipe
     * exists — so the same checkout is nameless by files and Element Web by
     * source.
     */
    public function test_the_url_names_the_project_where_the_files_do_not(): void
    {
        $project = $this->fixtureDir();
        file_put_contents($project . '/package.json', (string) json_encode(['name' => 'element-web', 'private' => true]));
        file_put_contents($project . '/pnpm-lock.yaml', "lockfileVersion: 9\n");
        file_put_contents($project . '/pnpm-workspace.yaml', "packages:\n  - \"apps/*\"\n");
        mkdir($project . '/apps/web', 0777, true);
        file_put_contents($project . '/apps/web/Dockerfile', "FROM node\n");

        $byFiles = \App\Lib\Deploy\DetectProjectStrategy::detect($project);
        $bySource = \App\Lib\Deploy\DetectProjectStrategy::detect($project, self::URL);

        $this->assertNull($byFiles['platform'] ?? null);
        $this->assertSame('element-web', $bySource['platform']);
        $this->assertSame('dockerfile', $bySource['strategy']);
        $this->assertSame('github.com/element-hq/element-web', $bySource['source_recipe']);
    }

    /**
     * A deploy records the recipe id and resolves the manifest back through
     * the registry on every later read; the id has to keep answering.
     */
    public function test_the_registry_resolves_the_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('element-web'));
    }

    /** Somewhere to describe a manifest against, with no Dockerfile inside. */
    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/pa-element-web-recipe-' . bin2hex(random_bytes(6));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    private function context(): ProjectContext
    {
        $dir = $this->fixtureDir();

        return ProjectContext::make($dir, ProjectContext::listRootFiles($dir));
    }
}
