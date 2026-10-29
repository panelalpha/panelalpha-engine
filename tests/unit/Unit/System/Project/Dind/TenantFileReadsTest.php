<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * The deploy reads the account's .env, .env.example and compose files as
 * root and writes what it read into files the tenant owns. A link the tenant
 * planted in their place, or a path out of the project, must be refused
 * rather than copy another account's file into the tenant's.
 */
class TenantFileReadsTest extends TestCase
{
    private string $root;

    private string $projectDir;

    private string $otherProject;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir().'/pa-tenant-read-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->root.'/home/alice/project';
        $this->otherProject = $this->root.'/home/bob/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->otherProject, 0777, true);
        file_put_contents($this->otherProject.'/.env', "DB_PASSWORD=bob-secret\n");
        file_put_contents($this->otherProject.'/docker-compose.yml', "services:\n  bob:\n    image: bob-secret/app\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_symlinked_env_example_is_not_copied_into_env_default(): void
    {
        symlink($this->otherProject.'/.env', $this->projectDir.'/.env.example');

        $this->assertRefused(fn () => $this->environment()->apply());
        $this->assertNoSecretIn('.env.default');
        $this->assertNoSecretIn('.env');
    }

    public function test_a_symlinked_env_is_not_read_into_the_engine_env_files(): void
    {
        symlink($this->otherProject.'/.env', $this->projectDir.'/.env');

        $this->assertRefused(fn () => $this->environment(['env_vars' => ['APP_NAME' => 'Custom']])->apply());
        $this->assertNoSecretIn('.env.default');
        $this->assertNoSecretIn(EngineArtifacts::ENV_OVERRIDES);
    }

    public function test_a_symlinked_compose_file_is_not_copied_into_the_run_file(): void
    {
        symlink($this->otherProject.'/docker-compose.yml', $this->projectDir.'/docker-compose.yml');

        $this->assertRefused(fn () => $this->refreshRunFile());
        $this->assertNoSecretIn(EngineArtifacts::RUN_COMPOSE);
    }

    public function test_a_symlinked_override_is_not_copied_into_the_hardened_override(): void
    {
        file_put_contents($this->projectDir.'/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        symlink($this->otherProject.'/docker-compose.yml', $this->projectDir.'/'.Paths::CLIENT_OVERRIDE_FILENAME);

        $this->assertRefused(fn () => $this->refreshRunFile());
        $this->assertNoSecretIn(EngineArtifacts::RUN_CLIENT_OVERRIDE);
    }

    public function test_a_compose_file_in_a_symlinked_directory_is_not_read(): void
    {
        symlink($this->otherProject, $this->projectDir.'/docker');
        file_put_contents(
            $this->projectDir.'/docker-compose.yml',
            "services:\n  web:\n    extends:\n      file: docker/docker-compose.yml\n      service: bob\n"
        );

        $this->assertRefused(fn () => $this->refreshRunFile());
        $this->assertNoSecretIn(EngineArtifacts::RUN_COMPOSE);
    }

    public function test_a_symlink_that_stays_in_the_project_is_still_read(): void
    {
        file_put_contents($this->projectDir.'/compose.prod.yml', "services:\n  web:\n    image: nginx:alpine\n");
        symlink('compose.prod.yml', $this->projectDir.'/docker-compose.yml');

        $this->refreshRunFile();

        $this->assertStringContainsString('nginx:alpine', (string) file_get_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE));
    }

    private function assertRefused(\Closure $deploy): void
    {
        try {
            $deploy();
            $this->fail('The engine read a file out of the account home.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to read', $e->getMessage());
        }
    }

    private function assertNoSecretIn(string $relative): void
    {
        $path = $this->projectDir.'/'.$relative;
        if (is_file($path)) {
            $this->assertStringNotContainsString('bob-secret', (string) file_get_contents($path), "{$relative} holds another account's file");
        }
    }

    private function refreshRunFile(): void
    {
        (new UserComposeStrategy($this->dind(['deploy_strategy' => 'compose'])))->refreshRunFile($this->projectDir, '1000:1000');
    }

    /** @param  array<string, mixed>  $details */
    private function environment(array $details = []): ProjectEnvironment
    {
        return new class($this->dind($details)) extends ProjectEnvironment
        {
            protected function envIsTracked(): bool
            {
                return false;
            }
        };
    }

    /** @param  array<string, mixed>  $details */
    private function dind(array $details): DindProject
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }

            public function getMainDomain(): ?Domain
            {
                return null;
            }
        };
        $model->username = 'alice';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $model))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }
}
