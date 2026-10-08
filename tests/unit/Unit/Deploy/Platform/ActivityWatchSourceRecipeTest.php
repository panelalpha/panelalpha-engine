<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

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
    private const URL = 'https://github.com/ActivityWatch/activitywatch';

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
     * A source recipe is applied without running the probes, so it has to
     * state the dockerfile the deployability check will look for or the
     * deploy fails with "Dockerfile strategy selected but Dockerfile is
     * missing."
     */
    public function test_it_names_its_own_dockerfile(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('Dockerfile', $recipe->describe($this->context())['dockerfile']);
    }

    /**
     * The compose file the dockerfile strategy writes: the repository root as
     * the build context (the Dockerfile addresses `aw-server/...` inside it)
     * and aw-server's 5600 published on the same host port.
     */
    public function test_the_generated_compose_publishes_the_server_port(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $decision = $recipe->describe($this->context());
        $service = Yaml::parse(DeployCompose::dockerfile(
            $decision['dockerfile'] ?? 'Dockerfile',
            (int) $decision['port_hint'],
            $decision
        ))['services']['app'];

        $this->assertSame(['5600:5600'], $service['ports']);
        // A root-level `Dockerfile` with no build args renders as the bare
        // context string; the nested form carries the file name with it.
        $this->assertSame('.', $service['build']);
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
     * The recipe outranks the files. The checkout is nameless by files — no
     * entry point at the root — and ActivityWatch by source.
     */
    public function test_the_url_names_the_project_where_the_files_do_not(): void
    {
        $project = $this->fixtureDir();
        file_put_contents($project . '/pyproject.toml', "[tool.poetry]\nname = \"activitywatch\"\npackage-mode = false\n");
        file_put_contents($project . '/poetry.lock', "package = []\n");
        mkdir($project . '/aw-server', 0777, true);

        $byFiles = DetectProjectStrategy::detect($project);
        $bySource = DetectProjectStrategy::detect($project, self::URL);

        $this->assertSame('python', $byFiles['strategy']);
        $this->assertSame('github.com/activitywatch/activitywatch', $bySource['source_recipe']);
        $this->assertSame('dockerfile', $bySource['strategy']);
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
        $this->assertStringContainsString('0.0.0.0', $contents);
    }

    /** A deploy records the recipe id and resolves it back on every read. */
    public function test_the_registry_resolves_the_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('activitywatch'));
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/pa-activitywatch-recipe-' . bin2hex(random_bytes(6));
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
