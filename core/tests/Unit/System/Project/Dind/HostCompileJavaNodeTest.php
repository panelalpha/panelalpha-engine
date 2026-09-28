<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Inner\HostCommands;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\Lib\Deploy\CacheManager\NodeBuildImage;
use App\Lib\Deploy\Dind\DindImageStore;
use Tests\TestCase;

/**
 * A Java host build runs in one `docker run` of the gradle/maven image, which
 * has no Node. Tolgee's Gradle scripts look npm up while they configure, so
 * the build died before compiling anything (#141).
 */
class HostCompileJavaNodeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/java-node-hc-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/gradle', 0777, true);
        file_put_contents($this->dir . '/build.gradle', "apply from: './gradle/webapp.gradle'\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param ?string $tag what ensureNodeBuild() answers; false = must not be asked */
    private function buildImage(string|false|null $tag, string $strategy = 'java'): string
    {
        $bases = $this->createMock(SharedBaseImages::class);
        if ($tag === false) {
            $bases->expects($this->never())->method('ensureNodeBuild');
        } else {
            $bases->expects($this->once())->method('ensureNodeBuild')
                ->with('gradle:9-jdk25', 'node:20-bookworm-slim')
                ->willReturn($tag);
        }
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('bases')->willReturn($bases);
        $project = $this->createStub(Dind::class);
        $project->method('innerDocker')->willReturn($inner);

        $method = new \ReflectionMethod(HostCompile::class, 'commandRuntimeImage');

        return $method->invoke(
            new HostCompile($project),
            ['strategy' => $strategy, 'image' => 'gradle:9-jdk25'],
            $this->dir
        );
    }

    public function test_gradle_build_that_looks_npm_up_compiles_with_node(): void
    {
        file_put_contents($this->dir . '/gradle/utils.gradle', "ext { npmCommandName = resolveExecutable(\"npm\") }\n");
        $tag = (string) NodeBuildImage::tag('gradle:9-jdk25', 'node:20-bookworm-slim');

        $this->assertSame($tag, $this->buildImage($tag));
    }

    public function test_falls_back_to_the_recipe_image_when_the_node_image_cannot_be_built(): void
    {
        file_put_contents($this->dir . '/gradle/utils.gradle', "ext { npmCommandName = resolveExecutable(\"npm\") }\n");

        $this->assertSame('gradle:9-jdk25', $this->buildImage(null));
    }

    public function test_plain_java_build_keeps_its_image(): void
    {
        $this->assertSame('gradle:9-jdk25', $this->buildImage(false));
    }

    public function test_node_build_image_is_built_on_the_host_when_missing(): void
    {
        [$bases, , $commands] = $this->bases(hostHasImage: false);

        $tag = $bases->ensureNodeBuild('gradle:9-jdk25', 'node:20-bookworm-slim');

        $this->assertSame(NodeBuildImage::tag('gradle:9-jdk25', 'node:20-bookworm-slim'), $tag);
        $this->assertCount(1, $commands->built);
        $this->assertStringContainsString('docker build', $commands->built[0]);
        $this->assertStringContainsString((string) $tag, $commands->built[0]);
        $this->assertStringContainsString('COPY --from=node /usr/local/bin/', $commands->built[0]);
    }

    public function test_node_build_image_already_on_the_host_is_not_rebuilt(): void
    {
        [$bases, , $commands] = $this->bases(hostHasImage: true);

        $this->assertNotNull($bases->ensureNodeBuild('gradle:9-jdk25', 'node:20-bookworm-slim'));
        $this->assertSame([], $commands->built);
    }

    public function test_failed_node_build_image_answers_null(): void
    {
        [$bases] = $this->bases(hostHasImage: false, buildFails: true);

        $this->assertNull($bases->ensureNodeBuild('gradle:9-jdk25', 'node:20-bookworm-slim'));
    }

    /** @return array{SharedBaseImages, HostCommands, object} */
    private function bases(bool $hostHasImage, bool $buildFails = false): array
    {
        $commands = new class {
            /** @var list<string> */
            public array $built = [];
        };

        $system = $this->createStub(System::class);
        if ($hostHasImage) {
            $system->method('execOnHost')->willReturn('');
        } else {
            $system->method('execOnHost')->willThrowException(new \Exception('No such image'));
        }
        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);

        $inner = $this->createStub(InnerDocker::class);
        $host = $this->createStub(HostCommands::class);
        $host->method('cancellable')->willReturnCallback(
            function (array|string $cmd) use ($commands, $buildFails): void {
                if ($buildFails) {
                    throw new \Exception('exec /bin/sh: exec format error');
                }
                $commands->built[] = (string) $cmd;
            }
        );
        $inner->method('host')->willReturn($host);
        $inner->method('imageStore')->willReturn(new DindImageStore());
        $inner->method('dind')->willReturn($project);

        return [new SharedBaseImages($inner), $host, $commands];
    }
}
