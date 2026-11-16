<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Probes\DockerfileNamedProbe;

/**
 * A root `<name>.dockerfile` is built, but only for a project nothing else
 * claims (otobo's `otobo.web.dockerfile` form was never found).
 */
class DockerfileNamedProbeTest extends ProbeTestCase
{
    private function evaluate(): array|bool
    {
        return (new DockerfileNamedProbe())->evaluate($this->context());
    }

    public function test_the_only_named_dockerfile_is_found_with_its_port(): void
    {
        $this->write('app.dockerfile', "FROM nginx:alpine\nEXPOSE 8081\n");
        $this->write('README.md', '# app');

        $this->assertSame(['dockerfile' => 'app.dockerfile', 'port_hint' => 8081], $this->evaluate());

        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame('dockerfile', $decision['strategy']);
        $this->assertSame('app.dockerfile', $decision['dockerfile']);
    }

    public function test_a_development_variant_is_not_a_candidate(): void
    {
        $this->write('dev.dockerfile', 'FROM alpine');
        $this->write('app.local.Dockerfile', 'FROM alpine');

        $this->assertFalse($this->evaluate());
    }

    /** otobo ships four: which one is the site is a guess. */
    public function test_several_with_none_marked_for_production_give_nothing(): void
    {
        foreach (['web', 'nginx', 'elasticsearch', 'selenium'] as $name) {
            $this->write("otobo.{$name}.dockerfile", 'FROM alpine');
        }
        $this->assertFalse($this->evaluate());

        $this->write('otobo.prod.dockerfile', 'FROM alpine');
        $this->assertSame(['dockerfile' => 'otobo.prod.dockerfile'], $this->evaluate());
    }

    /** Laravel tutorials keep `php.dockerfile` at the root: the app is still Laravel/PHP. */
    public function test_a_project_a_language_recipe_claims_is_left_to_it(): void
    {
        $this->write('php.dockerfile', 'FROM php:8.3-fpm');
        $this->writeJson('composer.json', ['require' => ['php' => '^8.3']]);
        $this->write('index.php', '<?php echo 1;');

        $this->assertNotSame('dockerfile', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_package_no_recipe_claims_is_built_from_its_named_dockerfile(): void
    {
        $this->write('app.dockerfile', "FROM node:22-alpine\nEXPOSE 3000\n");
        $this->writeJson('package.json', ['name' => 'x', 'scripts' => ['lint' => 'eslint .']]);

        $this->assertSame('dockerfile', DetectProjectStrategy::detect($this->dir)['strategy']);
    }

    public function test_a_plain_dockerfile_still_wins(): void
    {
        $this->write('Dockerfile', 'FROM alpine');
        $this->write('app.dockerfile', 'FROM nginx');

        $this->assertSame('Dockerfile', DetectProjectStrategy::detect($this->dir)['dockerfile']);
    }
}
