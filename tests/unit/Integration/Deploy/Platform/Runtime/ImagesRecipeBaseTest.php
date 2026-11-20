<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\HostNodeBuild;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * The base image a framework recipe builds from, chosen from the checkout, and the version lists the prewarmer reads.
 */
class ImagesRecipeBaseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-images-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_without_a_checkout_a_recipe_builds_from_the_default_node(): void
    {
        $this->assertSame(Images::NODE_IMAGE, Images::recipeBaseImage('nextjs', 'node'));
    }

    public function test_a_bun_project_builds_from_bun(): void
    {
        file_put_contents($this->dir.'/package.json', '{"name":"app","packageManager":"bun@1.1.0"}');
        file_put_contents($this->dir.'/bun.lockb', 'lock');

        $this->assertSame(HostNodeBuild::BUN_IMAGE, Images::recipeBaseImage('nextjs', 'node', $this->dir));
    }

    public function test_a_node_project_builds_from_the_node_its_package_asks_for(): void
    {
        file_put_contents($this->dir.'/package.json', '{"name":"app","engines":{"node":"20"}}');

        $image = Images::recipeBaseImage('nextjs', 'node', $this->dir);

        $this->assertSame(NodeRuntime::imageFor($this->dir, ['name' => 'app', 'engines' => ['node' => '20']]), $image);
        $this->assertStringStartsWith('node:20', $image);
    }

    public function test_a_strategy_that_requires_no_runtime_preloads_nothing(): void
    {
        $this->assertSame([], Images::preloadImagesFor(Strategies::DOCKERFILE, null, $this->dir));
    }

    public function test_the_version_lists_are_the_runtimes_own(): void
    {
        $this->assertSame(PhpRuntime::minors(), Images::phpImageMinors());
        $this->assertSame(NodeRuntime::majors(), Images::nodeMajors());
    }
}
