<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Strategies;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\RailpackStrategy;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * After a Railpack build, the image runs if it starts something, else the shipped pages or
 * the placeholder; a failed build gives the placeholder only where nothing runs yet.
 */
class RailpackStrategyTest extends TestCase
{
    private string $projectDir;

    /** @var list<string>|null */
    private ?array $imageCommand = ['npm run start'];

    private ?string $compose = null;

    private bool $placeholderWritten = true;

    private bool $built = true;

    /** @var list<array<string, mixed>>|null null when the stack cannot be listed */
    private ?array $running = [];

    /** @var list<string> */
    private array $nginxConfs = [];

    /** @var array<string, mixed> */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir().'/pa-railpack-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->projectDir));
        parent::tearDown();
    }

    public function test_an_image_that_starts_something_is_what_runs(): void
    {
        $this->apply();

        $service = $this->appService();
        $this->assertSame('panelalpha-acme-app:latest', $service['image']);
        $this->assertSame(['3000:3000'], $service['ports']);
        $this->assertSame([], $this->snapshot);
    }

    public function test_an_image_with_nothing_to_start_gives_way_to_the_pages_the_project_ships(): void
    {
        $this->imageCommand = ['/bin/bash'];
        mkdir($this->projectDir.'/dist');
        file_put_contents($this->projectDir.'/dist/index.html', '<!doctype html><title>h5bp</title>');

        $this->apply();

        $service = $this->appService();
        $this->assertStringStartsWith('nginx', $service['image']);
        $this->assertContains('./dist:/usr/share/nginx/html:ro', $service['volumes']);
        $this->assertSame(Strategies::STATIC, $this->snapshot['deploy_strategy']);
    }

    public function test_an_image_with_no_command_at_all_and_no_pages_falls_back_to_the_placeholder(): void
    {
        $this->imageCommand = null;

        $this->apply();

        $service = $this->appService();
        $this->assertStringStartsWith('nginx', $service['image']);
        $this->assertContains('./:/usr/share/nginx/html/:ro', $service['volumes']);
        $this->assertSame(Strategies::FALLBACK, $this->snapshot['deploy_strategy']);
    }

    /** The engine's own page answers 404 everywhere, so a monitor or a browser does not read the site as up. */
    public function test_the_placeholder_is_served_by_the_not_found_config(): void
    {
        $this->strategy()->applyFallback($this->projectDir, '1001:1001', 'https://github.com/anomalyco/opencode');

        $this->assertSame(['placeholder'], $this->nginxConfs);
        $this->assertContains('./:/usr/share/nginx/html/:ro', $this->appService()['volumes']);
    }

    /** An index.html the user shipped is a site of their own, served as one, with its 200. */
    public function test_a_page_the_user_shipped_keeps_the_static_site_config(): void
    {
        $this->placeholderWritten = false;

        $this->strategy()->applyFallback($this->projectDir, '1001:1001', 'https://github.com/anomalyco/opencode');

        $this->assertSame(['site'], $this->nginxConfs);
    }

    public function test_a_failed_first_build_falls_back_to_the_placeholder(): void
    {
        $this->built = false;

        $this->apply();

        $this->assertSame(['placeholder'], $this->nginxConfs);
        $this->assertSame(Strategies::FALLBACK, $this->snapshot['deploy_strategy']);
    }

    /** The placeholder over a running app would take the site down and keep the deploy from putting it back. */
    public function test_a_failed_build_with_an_app_running_fails_and_writes_nothing(): void
    {
        $this->built = false;
        $this->running = [['Id' => str_repeat('b', 64), 'Names' => ['/project-app-1']]];

        try {
            $this->apply();
            $this->fail('the deploy fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('running version was kept', $e->getMessage());
        }

        $this->assertNull($this->compose);
        $this->assertSame([], $this->snapshot);
    }

    public function test_a_failed_build_whose_stack_cannot_be_listed_fails_too(): void
    {
        $this->built = false;
        $this->running = null;

        $this->expectException(\RuntimeException::class);

        $this->apply();
    }

    private function apply(): void
    {
        $this->strategy()->applyRailpackOrFallback($this->projectDir, '1001:1001', 'https://github.com/h5bp/html5-boilerplate');
    }

    private function strategy(): RailpackStrategy
    {
        return new class ($this->dind(), $this->built) extends RailpackStrategy {
            public function __construct(Dind $dind, private readonly bool $built)
            {
                parent::__construct($dind);
            }

            public function buildImage(string $projectDir, ?string $chown, string $imageName): bool
            {
                return $this->built;
            }
        };
    }

    /** @return array<string, mixed> */
    private function appService(): array
    {
        $this->assertNotNull($this->compose, 'a compose file was written');

        return Yaml::parse($this->compose)['services']['app'];
    }

    private function dind(): Dind
    {
        $model = new User();
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $filesystem = $this->createStub(SystemFilesystem::class);
        $filesystem->method('fileExists')->willReturnCallback(static fn (string $path): bool => is_file($path));
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(fn (string|array $cmd): string => $this->inspect((array) $cmd));

        $writer = $this->createStub(ComposeWriter::class);
        $writer->method('writeGeneratedCompose')->willReturnCallback(function (string $dir, string $yaml): void {
            $this->compose = $yaml;
        });
        $writer->method('writeFallbackIndexHtml')->willReturnCallback(fn (): bool => $this->placeholderWritten);
        $writer->method('writePlaceholderNginxConf')->willReturnCallback(function (): void {
            $this->nginxConfs[] = 'placeholder';
        });
        $writer->method('writeStaticNginxConf')->willReturnCallback(function (): void {
            $this->nginxConfs[] = 'site';
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('userModel')->willReturn($model);
        $dind->method('username')->willReturn('acme');
        $dind->method('system')->willReturn($system);
        $dind->method('composeWriter')->willReturn($writer);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('freezeDeploySnapshot')->willReturnCallback(function (array $snapshot): void {
            $this->snapshot = array_merge($this->snapshot, $snapshot);
        });

        return $dind;
    }

    /** @param list<string> $command */
    private function inspect(array $command): string
    {
        if (LocalRoot::action($command) === 'account-docker.listContainers') {
            return $this->running === null ? 'Cannot connect to the Docker daemon' : (string) json_encode($this->running);
        }
        if (LocalRoot::action($command) !== 'account-docker.images') {
            return '';
        }

        return (string) json_encode([[
            'Id' => 'sha256:'.str_repeat('a', 64),
            'Config' => ['Cmd' => $this->imageCommand, 'ExposedPorts' => ['3000/tcp' => new \stdClass()]],
        ]]);
    }
}
