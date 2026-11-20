<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\KimaiSourceRecipeFixtures;

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
    use KimaiSourceRecipeFixtures;

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
