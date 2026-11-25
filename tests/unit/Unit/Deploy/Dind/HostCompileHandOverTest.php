<?php

namespace Tests\Unit\Deploy\Dind;

use App\Exceptions\BuildHandedOverException;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;

/**
 * `next build` writes `.next/BUILD_ID` and no `out/` when `output: "export"` is not in effect, and `out/index.html` when it
 * is: each Next.js recipe hands the other's build over, and that recipe's compile from the same inputs keeps it.
 */
class HostCompileHandOverTest extends TestCase
{
    private const STARTED = 1_800_000_000;

    private const EXPORT = ['platform' => 'nextjs-export', 'strategy' => 'nextjs'];

    private const SERVER = ['platform' => 'nextjs', 'strategy' => 'nextjs'];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-server-build-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    public function test_a_fresh_server_build_without_pages_is_handed_to_the_server_recipe(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED + 30);
        $compile = $this->compile();
        $this->setInputs($compile, 'lastBuildInputs', 'inputs');

        try {
            $this->refuse($compile, self::EXPORT, 'out');
            $this->fail('expected the server build to be refused');
        } catch (BuildHandedOverException $e) {
            $this->assertSame('nextjs', $e->recipe);
            $this->assertSame(
                'The build wrote .next/BUILD_ID: it is a nextjs build, not a nextjs-export one',
                $e->getMessage()
            );
        }
        $this->assertTrue($this->reuses($compile, 'inputs'));
        $this->assertFalse($this->reuses($compile, 'inputs'), 'only the next compile keeps it');
    }

    public function test_a_workspace_apps_marker_is_read_beside_its_output(): void
    {
        $this->write('apps/web/.next/BUILD_ID', self::STARTED + 30);

        $this->expectException(BuildHandedOverException::class);
        $this->expectExceptionMessage('The build wrote apps/web/.next/BUILD_ID:');
        $this->refuse($this->compile(), self::EXPORT, 'apps/web/out');
    }

    public function test_a_server_recipes_build_that_exported_is_handed_to_the_export_recipe(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED + 30);
        $this->write('out/index.html', self::STARTED + 30);

        $this->expectException(BuildHandedOverException::class);
        $this->expectExceptionMessage('The build wrote out/index.html: it is a nextjs-export build, not a nextjs one');
        $this->refuse($this->compile(), self::SERVER, '.next');
    }

    public function test_a_server_build_is_served_by_the_server_recipe(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED + 30);
        $this->write('out/index.html', self::STARTED - 60);

        $this->refuse($compile = $this->compile(), self::SERVER, '.next');

        $this->assertNull($this->offered($compile));
    }

    public function test_an_export_that_wrote_its_pages_is_served(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED + 30);
        $this->write('out/index.html', self::STARTED + 30);

        $this->refuse($compile = $this->compile(), self::EXPORT, 'out');

        $this->assertNull($this->offered($compile));
    }

    public function test_a_marker_from_an_earlier_build_is_not_this_builds(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED - 60);

        $this->refuse($compile = $this->compile(), self::EXPORT, 'out');

        $this->assertNull($this->offered($compile));
    }

    public function test_a_recipe_without_hand_over_is_left_to_the_index_check(): void
    {
        $this->write('.next/BUILD_ID', self::STARTED + 30);

        $this->refuse($compile = $this->compile(), ['platform' => 'vite', 'strategy' => 'vite'], 'out');

        $this->assertNull($this->offered($compile));
    }

    public function test_other_inputs_are_built_again(): void
    {
        $compile = $this->compile();
        $this->setInputs($compile, 'reusableBuildInputs', 'inputs');

        $this->assertFalse($this->reuses($compile, 'other inputs'));
        $this->assertFalse($this->reuses($compile, 'inputs'), 'a refused build is offered once');
    }

    public function test_the_serving_address_is_not_a_build_input(): void
    {
        $inputs = new \ReflectionMethod(HostCompile::class, 'buildInputs');
        $static = $inputs->invoke(null, '/p', 'node:22', 'npm ci', 'npm run build', ['env' => ['PORT' => '8080', 'HOST' => '0.0.0.0', 'CI' => '1']]);
        $server = $inputs->invoke(null, '/p', 'node:22', 'npm ci', 'npm run build', ['env' => ['CI' => '1', 'HOSTNAME' => '0.0.0.0', 'PORT' => '3000']]);
        $other = $inputs->invoke(null, '/p', 'node:22', 'npm ci', 'npm run build', ['env' => ['CI' => '1', 'NEXT_PUBLIC_API' => 'x']]);

        $this->assertSame($static, $server);
        $this->assertNotSame($static, $other);
    }

    private function write(string $relative, int $mtime): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, 'x');
        touch($path, $mtime);
    }

    /** @param  array<string, string>  $decision */
    private function refuse(HostCompile $compile, array $decision, string $output): void
    {
        (new \ReflectionMethod($compile, 'handOverToOtherRecipe'))->invoke($compile, $decision, $this->dir, $output);
    }

    private function reuses(HostCompile $compile, string $inputs): bool
    {
        return (new \ReflectionMethod($compile, 'reusesBuild'))->invoke($compile, $inputs);
    }

    private function offered(HostCompile $compile): ?string
    {
        return (new \ReflectionProperty($compile, 'reusableBuildInputs'))->getValue($compile);
    }

    private function setInputs(HostCompile $compile, string $property, string $value): void
    {
        (new \ReflectionProperty($compile, $property))->setValue($compile, $value);
    }

    private function compile(): HostCompile
    {
        $system = new class extends System
        {
            public function __construct() {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(LocalRoot::argv(array_values((array) $cmd)));
                $process->run();

                return $process;
            }
        };
        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($this->createStub(User::class));
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new \ReflectionProperty($compile, 'buildStartedAt'))->setValue($compile, self::STARTED);

        return $compile;
    }
}
