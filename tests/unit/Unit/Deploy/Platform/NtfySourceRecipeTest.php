<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\NtfySourceRecipeFixtures;

/**
 * ntfy ships three Dockerfiles and detection built the wrong one.
 *
 * The root `Dockerfile` — the file a repository is expected to put its
 * deployable image in — is the *release* file: `FROM alpine`, `COPY ntfy
 * /usr/bin`, `ENTRYPOINT ["ntfy"]`. It copies a binary GoReleaser produces in
 * CI into an image that does not have it, so building it from a clean
 * checkout gives a container that starts and exits immediately and restart-
 * loops. That was the whole observed failure.
 *
 * `Dockerfile-build` is the file the project's own `make docker-dev` passes
 * to `docker build`, and the only one that compiles the server — docs, then
 * the web UI (which the binary consumes through `//go:embed all:dist`), then
 * the Go build. Its final stage is the default last stage, so no `--target`
 * is involved.
 *
 * What is asserted here is the recipe and the compose file it produces — a
 * deploy is the only thing that can say ntfy installs.
 */
class NtfySourceRecipeTest extends TestCase
{
    use NtfySourceRecipeFixtures;

    public function test_the_repository_url_resolves_to_the_ntfy_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL); // @phpstan-ignore-line — resolves through the shipped tree

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('dockerfile', $recipe->strategy);
        $this->assertSame('dockerfile', $recipe->runtime);
        // 80 is what both Dockerfiles EXPOSE — the binary's `serve` default is
        // `:80`. The hosting proxy owns 80 on the host, so the compose file
        // publishes 8080:80.
        $this->assertSame(80, $recipe->port);
    }

    /**
     * A deploy records the recipe's id and resolves the manifest back through
     * the registry on every later read; the id this recipe inherits — the
     * generic dockerfile platform, as endurain and pyfedi also use — has to
     * keep answering.
     */
    public function test_the_registry_resolves_the_inherited_id(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);
        $this->assertSame('dockerfile', $recipe->id);
        $this->assertNotNull(PlatformRegistry::find($recipe->id));
    }

    /** No `detect`: the path chose this recipe, and nothing should re-decide. */
    public function test_it_is_found_by_path_alone(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame([], $recipe->detect);
    }

    /**
     * The image ENTRYPOINT is a bare `ntfy`, and the binary has subcommands:
     * with no arguments it prints help and exits 0. The repository's own
     * docker-compose.yml passes `command: serve`; this is the same line as an
     * override layer, because the generated compose is the dockerfile
     * strategy's and only needs one field added to it.
     */
    public function test_the_override_tells_the_binary_to_serve(): void
    {
        $config = $this->appConfig();
        $this->assertNotNull($config);
        $this->assertSame(\App\Lib\Deploy\Platform\AppConfig\AppConfig::COMPOSE_OVERRIDE, $config->composeMode());

        $compose = Yaml::parse((string) $config->compose());
        $this->assertSame(['serve'], $compose['services']['app']['command']);
    }

    private function appConfig(): ?\App\Lib\Deploy\Platform\AppConfig\AppConfig
    {
        return \App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory::read(
            new \App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource(),
            SourceRecipes::defaultDirectory() . '/github.com/binwiederhier/ntfy',
            true
        );
    }
}
