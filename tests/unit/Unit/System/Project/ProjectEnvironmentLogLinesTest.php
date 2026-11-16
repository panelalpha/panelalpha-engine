<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Tests\TestCase;

/**
 * The deploy log lines and files ProjectEnvironment::apply() leaves for a project with no
 * env_vars, nested templates and a compose `env_file:`, and for one with env_vars.
 */
class ProjectEnvironmentLogLinesTest extends TestCase
{
    private string $tmpRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->tmpRoot = sys_get_temp_dir() . '/pa-env-lines-' . bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot . '/home/envlines/project';
        mkdir($this->projectDir . '/backend', 0777, true);
        mkdir($this->projectDir . '/ops', 0777, true);
        mkdir($this->tmpRoot . '/users/envlines', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        DeployLogger::deleteUserLogs('envlines');
        parent::tearDown();
    }

    public function test_defaults_with_nested_templates(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\n");
        file_put_contents($this->projectDir . '/backend/.env.example', "SECRET_KEY=changeme\nDEBUG=1\n");

        $lines = $this->applied([]);

        $this->assertSame([
            'Created backend/.env from .env.example',
            'Replaced the published placeholder in SECRET_KEY of backend/.env with a generated secret',
            'Using default environment variables (source: .env.example)',
        ], $lines);
        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->projectDir . '/.env'));
        $this->assertStringNotContainsString('changeme', (string) file_get_contents($this->projectDir . '/backend/.env'));
    }

    public function test_an_env_file_with_nothing_to_copy_is_created_empty(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yml', "services:\n  app:\n    image: nginx\n    env_file: [ops/worker.env]\n");

        $lines = $this->applied(['deploy_strategy' => 'compose']);

        $this->assertSame([
            'Created empty ops/worker.env (required by compose env_file)',
            'Using default environment variables (source: none)',
        ], $lines);
        $this->assertSame('', file_get_contents($this->projectDir . '/ops/worker.env'));
    }

    public function test_env_vars_are_merged_rather_than_defaulted(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nDEBUG=0\n");

        $lines = $this->applied(['env_vars' => ['DEBUG' => '1', 'EMPTY' => '']]);

        $this->assertNotContains('Using default environment variables (source: .env.example)', $lines);
        $this->assertSame("APP_NAME=Demo\nDEBUG=1\n", file_get_contents($this->projectDir . '/.env'));
        $this->assertSame("APP_NAME=Demo\nDEBUG=0\n", file_get_contents($this->projectDir . '/.env.default'));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function applied(array $details): array
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'envlines';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));
        $dind = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot . '/home'), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $dind);

        $logger = DeployLogger::start('envlines');
        try {
            (new class ($dind) extends Dind\ProjectEnvironment {
                protected function envIsTracked(): bool
                {
                    return false;
                }
            })->apply();

            return array_values(array_column($logger->read()['lines'], 'msg'));
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }
    }
}
