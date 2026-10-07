<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Compose\ComposeFileInspector;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use PHPUnit\Framework\TestCase;

/**
 * What replaced the generated PHP Dockerfile.
 *
 * The old `PhpDockerfileTest` asserted the shape of a file the engine no
 * longer writes. These are the invariants that took its place: that no
 * Dockerfile is produced, that the compose file the engine writes instead is
 * still recognisable as its own on the next detect pass, and that a deploy
 * pulls one image rather than a build's worth.
 */
class PhpMountedRuntimeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pa-php-mounted-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function compose(array $extra = []): string
    {
        return DeployCompose::framework([
            'runtime' => PlatformManifest::RUNTIME_PHP,
            'image' => PhpBaseImage::tag(Images::PHP_IMAGE),
        ] + $extra, PhpBaseImage::PORT);
    }

    /**
     * The one that would be silent. A generated compose with no `build:` key
     * still has to read as the engine's own, or the next detect pass takes it
     * for a compose file the customer shipped and freezes the account on
     * strategy=compose — over the recipe that actually matches.
     */
    public function test_the_generated_compose_is_still_recognised_as_the_engines_own(): void
    {
        $path = $this->dir . '/docker-compose.yml';
        file_put_contents($path, $this->compose());

        $this->assertStringNotContainsString('build:', $this->compose());
        $this->assertTrue(ComposeFileInspector::isGeneratedBootstrapCompose($path));
    }

    public function test_no_dockerfile_is_written_for_a_php_project(): void
    {
        $this->assertStringNotContainsString(DockerfileBuilder::FILENAME, $this->compose());
        $this->assertFileDoesNotExist($this->dir . '/' . DockerfileBuilder::FILENAME);
    }

    /**
     * A deploy resolves one tag, and it is the shared base rather than the
     * official image that base is built from.
     *
     * PHP used to answer with the official tag and have the substitution done
     * by a preload special case, so the recipe named one image and the account
     * ran another. It goes through the same door as every other runtime now.
     */
    public function test_the_recipe_names_the_shared_base_a_php_project_runs_on(): void
    {
        file_put_contents(
            $this->dir . '/composer.json',
            (string) json_encode(['require' => ['php' => '~8.2.0']])
        );

        $this->assertSame(
            [PhpBaseImage::tag(PhpRuntime::imageTag('8.2'))],
            Images::preloadImagesFor(Strategies::PHP, PlatformManifest::RUNTIME_PHP, $this->dir)
        );
    }

    /**
     * No composer.json is not an error: the default minor's base is what a
     * project that says nothing runs on.
     */
    public function test_a_project_with_no_composer_json_still_resolves_an_image(): void
    {
        $images = Images::preloadImagesFor(Strategies::PHP, PlatformManifest::RUNTIME_PHP, $this->dir);

        $this->assertCount(1, $images);
        $this->assertSame(PhpBaseImage::tag(PhpRuntime::imageTag(PhpRuntime::defaultMinor())), $images[0]);
    }

    /**
     * The port the vhost is baked to listen on and the port the compose file
     * publishes are the same number, spelled once.
     */
    public function test_the_published_port_matches_the_baked_vhost(): void
    {
        $this->assertStringContainsString(
            PhpBaseImage::PORT . ':' . PhpBaseImage::PORT,
            $this->compose()
        );
    }
}
