<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Kimai's repository Dockerfile is multi-variant, and none of the variants the
 * engine would otherwise pick can be served.
 *
 * The file's last stage — the one BuildKit builds by default — is the FPM
 * variant: `FROM php:8.3-fpm-alpine`, `EXPOSE 9000`, FastCGI. The engine read
 * 9000 off the EXPOSE as an HTTP port and health-checked `http://…:9000/`,
 * which answers `Empty reply from server` while the application behind it is
 * perfectly healthy. The variant that can be served is selected by
 * `ARG BASE`, which half a dozen later `FROM ${BASE}-base` stages read.
 *
 * What is asserted here is only the recipe and the compose file it produces —
 * a deploy is the only thing that can say Kimai installs.
 */
class KimaiSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/kimai/kimai';

    public function test_the_repository_url_resolves_to_the_kimai_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('kimai', $recipe->id);
        $this->assertSame('dockerfile', $recipe->strategy);
        $this->assertSame('dockerfile', $recipe->runtime);
        // 8001 is the Apache variant's port. The file's EXPOSE 9000 belongs to
        // the FPM variant and is FastCGI, which nothing can proxy.
        $this->assertSame(8001, $recipe->port);
    }

    /** The whole reason the recipe exists: reach the Apache variant, not the FPM one. */
    public function test_it_builds_the_apache_variant_of_the_repository_dockerfile(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame(['BASE' => 'apache'], $recipe->buildArgs);
    }

    /**
     * Kimai's entrypoint blocks in a retry loop until DATABASE_URL resolves,
     * and it ships no database of its own — so the recipe has to say it needs
     * the account's.
     */
    public function test_it_asks_the_engine_for_a_mysql_database(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('mysql', $recipe->database);
    }

    /**
     * The compose file the dockerfile strategy writes: an Apache image on
     * 8001, built from the repository's own Dockerfile with the variant arg,
     * and pointed at the account's MySQL by name.
     */
    public function test_the_generated_compose_publishes_the_apache_port_and_passes_the_arg(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe);

        $decision = $recipe->describe(
            \App\Lib\Deploy\Platform\ProjectContext::make(
                $this->fixtureDir(),
                \App\Lib\Deploy\Platform\ProjectContext::listRootFiles($this->fixtureDir())
            )
        );
        $service = Yaml::parse(DeployCompose::dockerfile(
            $decision['dockerfile'] ?? 'Dockerfile',
            (int) $decision['port_hint'],
            $decision
        ))['services']['app'];

        $this->assertSame(['8001:8001'], $service['ports']);
        $this->assertSame(['BASE' => 'apache'], $service['build']['args']);
        // Not the `build: .` shorthand: a file name or an arg forces the map.
        $this->assertSame('.', $service['build']['context']);
    }

    /**
     * A deploy records the recipe id and resolves the manifest back through
     * the registry on every later read; the id has to keep answering.
     */
    public function test_the_registry_resolves_the_recipe_by_its_id(): void
    {
        $this->assertNotNull(PlatformRegistry::find('kimai'));
    }

    /** No `detect`: the path chose this recipe, and nothing should re-decide. */
    public function test_it_is_found_by_path_alone(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame([], $recipe->detect);
    }

    /** Somewhere to describe a manifest against, with no Dockerfile inside. */
    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir() . '/pa-kimai-recipe-' . bin2hex(random_bytes(6));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
