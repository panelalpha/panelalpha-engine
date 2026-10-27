<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ProjectEnvironment;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * Every `.env` file the engine writes sits in the account's own project
 * directory, where the tenant can put a symlink in its place. The engine
 * writes them as root, so each write must replace such a link, never write
 * or chown whatever it points at.
 */
class ProjectEnvironmentSymlinkTest extends TestCase
{
    private string $root;

    private string $projectDir;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir().'/pa-env-link-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->root.'/home/alice/project';
        mkdir($this->projectDir, 0777, true);
        $this->outside = $this->root.'/victim.txt';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_env_default_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "APP_NAME=Demo\n");
        $this->link('.env.default', 'victim');

        $this->environment($this->model(), tracked: false)->apply();

        $this->assertOutsideUntouched('victim');
        $this->assertReplacedWith('.env.default', 'APP_NAME=Demo');
    }

    public function test_env_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "APP_NAME=Demo\n");
        symlink($this->outside, $this->projectDir.'/.env');

        $this->environment($this->model(['env_vars' => ['APP_NAME' => 'Custom']]), tracked: false)->apply();

        $this->assertFileDoesNotExist($this->outside);
        $this->assertReplacedWith('.env', 'APP_NAME=Custom');
    }

    public function test_env_panelalpha_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        file_put_contents($this->projectDir.'/.env', "APP_NAME=Demo\n");
        $this->link(EngineArtifacts::ENV_OVERRIDES, 'victim');

        $this->environment($this->model(['env_vars' => ['APP_NAME' => 'Custom']]), tracked: true)->apply();

        $this->assertOutsideUntouched('victim');
        $this->assertReplacedWith(EngineArtifacts::ENV_OVERRIDES, 'APP_NAME=Custom');
    }

    public function test_attaching_env_panelalpha_refuses_a_symlinked_run_file(): void
    {
        file_put_contents($this->projectDir.'/.env', "APP_NAME=Demo\n");
        $runFile = Yaml::dump(['services' => ['app' => ['image' => 'acme/app', 'env_file' => '.env']]], 4, 2);
        $this->link(EngineArtifacts::RUN_COMPOSE, $runFile);

        try {
            $this->environment($this->model(['env_vars' => ['APP_NAME' => 'Custom']]), tracked: true)->apply();
            $this->fail('A symlinked run file was rewritten.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('it is a symlink', $e->getMessage());
        }

        $this->assertOutsideUntouched($runFile);
        $this->assertTrue(is_link($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE));
    }

    public function test_a_nested_env_copy_replaces_a_dangling_symlink_instead_of_creating_its_target(): void
    {
        mkdir($this->projectDir.'/server');
        file_put_contents($this->projectDir.'/server/.env.example', "APP_NAME=Nested\n");
        symlink($this->outside, $this->projectDir.'/server/.env');

        $this->environment($this->model(), tracked: false)->apply();

        $this->assertFileDoesNotExist($this->outside);
        $this->assertReplacedWith('server/.env', 'APP_NAME=Nested');
    }

    public function test_a_nested_env_copy_is_refused_below_a_symlinked_directory(): void
    {
        $other = $this->root.'/home/bob/project';
        mkdir($other, 0777, true);
        file_put_contents($other.'/.env.example', "APP_NAME=Nested\n");
        symlink($other, $this->projectDir.'/server');

        try {
            $this->environment($this->model(), tracked: false)->apply();
            $this->fail('A nested .env was written through a symlinked directory.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Refusing to', $e->getMessage());
        }

        $this->assertFileDoesNotExist($other.'/.env');
    }

    public function test_the_project_env_files_are_refused_when_the_project_directory_is_a_symlink(): void
    {
        $other = $this->root.'/home/bob/project';
        mkdir($other, 0777, true);
        file_put_contents($other.'/.env.example', "APP_NAME=Demo\n");
        exec('rm -rf '.escapeshellarg($this->projectDir));
        symlink($other, $this->projectDir);

        try {
            $this->environment($this->model(), tracked: false)->apply();
            $this->fail('The project .env files were written through a symlinked project directory.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Refusing to', $e->getMessage());
        }

        $this->assertSame(['.', '..', '.env.example'], scandir($other));
    }

    public function test_an_empty_env_file_the_run_file_names_replaces_a_dangling_symlink(): void
    {
        file_put_contents(
            $this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE,
            Yaml::dump(['services' => ['app' => ['image' => 'php:8-cli', 'env_file' => ['.env.app']]]], 4, 2)
        );
        symlink($this->outside, $this->projectDir.'/.env.app');

        $this->environment($this->model(), tracked: false)->apply();

        $this->assertFileDoesNotExist($this->outside);
        $this->assertReplacedWith('.env.app', '');
    }

    private function link(string $relative, string $outsideContents): void
    {
        file_put_contents($this->outside, $outsideContents);
        symlink($this->outside, $this->projectDir.'/'.$relative);
    }

    private function assertOutsideUntouched(string $contents): void
    {
        $this->assertSame($contents, file_get_contents($this->outside), 'the file the symlink pointed at was written');
    }

    private function assertReplacedWith(string $relative, string $expected): void
    {
        $path = $this->projectDir.'/'.$relative;
        $this->assertFalse(is_link($path), "{$relative} is still a symlink");
        $this->assertStringContainsString($expected, (string) file_get_contents($path));
    }

    private function environment(ModelsUser $model, bool $tracked): ProjectEnvironment
    {
        return new class($this->dind($model), $tracked) extends ProjectEnvironment
        {
            public function __construct(DindProject $dind, private bool $tracked)
            {
                parent::__construct($dind);
            }

            protected function envIsTracked(): bool
            {
                return $this->tracked;
            }
        };
    }

    private function dind(ModelsUser $model): DindProject
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $model))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }

    /** @param  array<string, mixed>  $details */
    private function model(array $details = []): ModelsUser
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));

        return $model;
    }
}
