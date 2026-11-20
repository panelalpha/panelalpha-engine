<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\HostBuilder;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * HostCompile::run() end to end with every host command recorded instead of run:
 * which recipes it compiles, the commands it issues in order, and what it refuses.
 */
class HostCompileRunTest extends TestCase
{
    private string $home = '';

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'hcrun' . bin2hex(random_bytes(3));
        $this->home = sys_get_temp_dir() . '/pa-hcrun-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/project', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->home]))->run();
        parent::tearDown();
    }

    public function test_a_recipe_served_from_its_own_image_compiles_nothing(): void
    {
        file_put_contents($this->home . '/project/index.php', '<?php echo 1;');

        $system = $this->compile([]);

        $this->assertSame([], $system->calls);
    }

    public function test_a_vite_site_is_compiled_on_the_host_and_handed_to_the_account(): void
    {
        $this->viteProject(builds: true);

        $system = $this->compile([]);

        $dir = $this->home . '/project';
        $this->assertSame([
            ['exec', ['prepare-cache']],
            ['exec', Privileged::host()->refreshPublicImage('node:22-bookworm-slim')],
            ['runProcess', [
                'node-build', $dir, 'node:22-bookworm-slim', 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', 'npm run build',
                '{"HOST":"'.TestNetwork::ANY.'","HOSTNAME":"'.TestNetwork::ANY.'","PORT":"8080","HUSKY":"0","CI":"1"}', 'isolated', 'node', '',
            ]],
            ['exec', Privileged::files()->changeOwnerRecursively('1001:1001', $dir . '/dist')],
        ], $system->calls);
    }

    public function test_without_a_build_cache_node_modules_is_not_isolated(): void
    {
        $this->viteProject(builds: true);

        $system = $this->compile(['cacheFails' => true]);

        $this->assertSame('shared', $system->calls[2][1][6] ?? null, json_encode($system->calls));
        $this->assertCount(4, $system->calls);
    }

    public function test_a_host_run_node_app_ships_its_node_modules(): void
    {
        $dir = $this->home . '/project';
        file_put_contents($dir . '/package.json', '{"name":"api","scripts":{"start":"node server.js"},"dependencies":{"express":"^4.19.0"}}');
        file_put_contents($dir . '/package-lock.json', '{}');
        file_put_contents($dir . '/server.js', 'require("express")().listen(3000);');
        mkdir($dir . '/node_modules');

        $system = $this->compile([]);

        $this->assertSame([
            ['exec', ['prepare-cache']],
            ['exec', Privileged::host()->refreshPublicImage('node:22-bookworm-slim')],
            ['runProcess', [
                'node-build', $dir, 'node:22-bookworm-slim', 'HUSKY=0 LEFTHOOK=0 CI=1 npm ci --no-audit --no-fund', '',
                '{"HOST":"'.TestNetwork::ANY.'","HOSTNAME":"'.TestNetwork::ANY.'","PORT":"3000","HUSKY":"0","CI":"1"}', 'shared', 'node', '',
            ]],
            ['exec', Privileged::files()->changeOwnerRecursively('1001:1001', $dir . '/node_modules')],
        ], $system->calls);
    }

    public function test_a_host_run_app_without_node_modules_fails(): void
    {
        $dir = $this->home . '/project';
        file_put_contents($dir . '/package.json', '{"name":"api","scripts":{"start":"node server.js"},"dependencies":{"express":"^4.19.0"}}');
        file_put_contents($dir . '/package-lock.json', '{}');
        file_put_contents($dir . '/server.js', 'require("express")().listen(3000);');

        $this->expectExceptionMessage('Install finished but node_modules is missing');

        $this->compile([]);
    }

    public function test_a_build_that_leaves_no_index_fails_by_name(): void
    {
        $this->viteProject(builds: false);

        $this->expectExceptionMessage('Static build finished but dist/index.html is missing');

        $this->compile([]);
    }

    public function test_a_failed_compile_reports_the_build_output(): void
    {
        $this->viteProject(builds: true);

        $this->expectExceptionMessage('vite: not found');

        $this->compile(['exit' => 127, 'stderr' => 'sh: 1: vite: not found']);
    }

    private function viteProject(bool $builds): void
    {
        $dir = $this->home . '/project';
        file_put_contents($dir . '/package.json', '{"name":"site","private":true,"scripts":{"build":"vite build"},"devDependencies":{"vite":"^5.0.0"}}');
        file_put_contents($dir . '/package-lock.json', '{}');
        file_put_contents($dir . '/index.html', '<!doctype html><title>x</title>');
        if ($builds) {
            mkdir($dir . '/dist');
            file_put_contents($dir . '/dist/index.html', '<!doctype html>');
        }
    }

    /** @param array{exit?: int, stderr?: string, cacheFails?: bool} $build */
    private function compile(array $build): object
    {
        $system = new class ($build) extends System {
            /** @var list<array{0: string, 1: list<string>}> */
            public array $calls = [];

            /** @param array{exit?: int, stderr?: string, cacheFails?: bool} $build */
            public function __construct(private array $build)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this) extends SystemFilesystem {
                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) @file_get_contents($path);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->calls[] = ['exec', (array) $cmd];
                if ((array) $cmd === ['prepare-cache'] && ($this->build['cacheFails'] ?? false)) {
                    throw new \RuntimeException('cache dir not writable');
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $this->calls[] = ['execOnHost', (array) $cmd];

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->calls[] = ['runProcess', (array) $cmd];
                $process = new Process([
                    'php', '-r', 'fwrite(STDERR, ' . var_export($this->build['stderr'] ?? '', true) . '); exit(' . ($this->build['exit'] ?? 0) . ');',
                ]);
                $process->run();

                return $process;
            }
        };

        $model = new User();
        $model->username = $this->username;
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $builder = $this->createStub(HostBuilder::class);
        $builder->method('prepareCacheArgv')->willReturn(['prepare-cache']);
        $builder->method('nodeBuildArgv')->willReturnCallback(
            static fn (EngineAccount $account, string $image, string $install, string $build, array $env = [], bool $isolate = true, bool $isNode = true, string $appRoot = ''): array => [
                'node-build', $account->projectDir(), $image, $install, $build, json_encode($env), $isolate ? 'isolated' : 'shared', $isNode ? 'node' : 'plain', $appRoot,
            ]
        );
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn($builder);

        $inner = $this->createStub(InnerDocker::class);
        $inner->method('imageStore')->willReturn(new DindImageStore());

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn($this->home . '/project');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount($this->username, $this->home, '1001:1001'));
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));

        (new HostCompile($dind))->run();

        return $system;
    }
}
