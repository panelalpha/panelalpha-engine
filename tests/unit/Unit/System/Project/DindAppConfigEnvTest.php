<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\EnvFile;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Tests\TestCase;

/**
 * A repository's own compose file reads `.env`, so the app config's `env:` reaches it there, under the account's
 * env_vars, and leaves again when the file stops naming it.
 */
class DindAppConfigEnvTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        $this->tmpRoot = sys_get_temp_dir().'/pa-dind-appenv-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
        $this->projectDir = $this->homeRoot.'/alice/project';
        mkdir($this->projectDir.'/.panelalpha', 0777, true);
        mkdir($this->tmpRoot.'/users/alice', 0777, true);
        file_put_contents($this->projectDir.'/.env.example', "APP_NAME=Demo\nMODE=base\n");
        file_put_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE, "services:\n  app:\n    image: acme/app\n    env_file: .env\n");
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_a_compose_project_gets_the_app_config_env_in_env(): void
    {
        $this->appConfig("env:\n  MODE: from-file\n  WORKERS: 4\n");
        $model = $this->model();

        $this->environment($model, tracked: false)->apply();

        $env = $this->env('.env');
        $this->assertSame(['from-file', '4', 'Demo'], [$env['MODE'], $env['WORKERS'], $env['APP_NAME']]);
        $this->assertSame('base', $this->env('.env.default')['MODE']);
        $this->assertFalse($model->usedCustomEnvVars(), 'the file is not the customer setting env vars');
        $this->assertSame([], Dind\ProjectEnvironment::appliedKeys($model));
    }

    public function test_the_accounts_env_vars_win_over_the_file(): void
    {
        $this->appConfig("env:\n  MODE: from-file\n  WORKERS: 4\n");
        $model = $this->model(['env_vars' => ['MODE' => 'from-account']]);

        $this->environment($model, tracked: false)->apply();

        $env = $this->env('.env');
        $this->assertSame(['from-account', '4'], [$env['MODE'], $env['WORKERS']]);
        $this->assertTrue($model->usedCustomEnvVars());
        $this->assertSame(['MODE'], Dind\ProjectEnvironment::appliedKeys($model));
    }

    public function test_a_key_the_file_no_longer_names_goes_back_to_the_base(): void
    {
        $this->appConfig("env:\n  MODE: from-file\n  WORKERS: 4\n");
        $model = $this->model();
        $this->environment($model, tracked: false)->apply();

        $this->appConfig("env:\n  WORKERS: 8\n");
        $this->environment($model, tracked: false)->apply();

        $env = $this->env('.env');
        $this->assertSame(['base', '8'], [$env['MODE'], $env['WORKERS']]);

        unlink($this->projectDir.'/.panelalpha/panelalpha.yaml');
        $this->environment($model, tracked: false)->apply();

        $this->assertEquals(['APP_NAME' => 'Demo', 'MODE' => 'base'], $this->env('.env'));
    }

    public function test_a_tracked_env_stays_as_committed_and_the_file_goes_to_env_panelalpha(): void
    {
        file_put_contents($this->projectDir.'/.env', "APP_NAME=Committed\n");
        $this->appConfig("env:\n  MODE: from-file\n");
        $model = $this->model();

        $this->environment($model, tracked: true)->apply();

        $this->assertSame("APP_NAME=Committed\n", file_get_contents($this->projectDir.'/.env'));
        $this->assertSame('from-file', $this->env(EngineArtifacts::ENV_OVERRIDES)['MODE']);
        $this->assertFalse($model->usedCustomEnvVars());
    }

    public function test_the_public_address_is_filled_in_and_engine_names_are_left_out(): void
    {
        $this->appConfig("env:\n  APP_URL: '\${PA_PUBLIC_URL}'\n  ORIGIN_HOST: '\${PA_PUBLIC_HOST}'\n  PA_INSTANCE_SECRET: mine\n");
        $model = $this->model([], 'shop.example.test');

        $this->environment($model, tracked: false)->apply();

        $env = $this->env('.env');
        $this->assertSame(['https://shop.example.test', 'shop.example.test'], [$env['APP_URL'], $env['ORIGIN_HOST']]);
        $this->assertArrayNotHasKey('PA_INSTANCE_SECRET', $env);
    }

    /** A generated run file carries the file's `env:` in `environment:`; `.env` stays as it was. */
    public function test_a_generated_run_file_project_is_left_as_it_was(): void
    {
        $this->appConfig("env:\n  MODE: from-file\n");
        $model = $this->model(['deploy_strategy' => 'node']);

        $this->environment($model, tracked: false)->apply();

        $this->assertSame('base', $this->env('.env')['MODE']);
    }

    private function appConfig(string $yaml): void
    {
        file_put_contents($this->projectDir.'/.panelalpha/panelalpha.yaml', $yaml);
    }

    /** @return array<string, string> */
    private function env(string $name): array
    {
        $values = [];
        foreach (EnvFile::parse((string) file_get_contents($this->projectDir.'/'.$name)) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }

    private function environment(ModelsUser $model, bool $tracked): Dind\ProjectEnvironment
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new class($runtime, $tracked) extends Dind\ProjectEnvironment
        {
            public function __construct(Dind $dind, private bool $tracked)
            {
                parent::__construct($dind);
            }

            protected function envIsTracked(): bool
            {
                return $this->tracked;
            }
        };
    }

    /** @param array<string, mixed> $details */
    private function model(array $details = [], ?string $domain = null): ModelsUser
    {
        $model = new class extends ModelsUser
        {
            public ?Domain $mainDomain = null;

            public function save(array $options = []): bool
            {
                return true;
            }

            public function getMainDomain(): ?Domain
            {
                return $this->mainDomain;
            }
        };
        $model->username = 'alice';
        $model->mainDomain = $domain === null ? null : new Domain(['domain' => $domain, 'type' => 'main']);
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
            'deploy_strategy' => Strategies::COMPOSE,
        ], $details));

        return $model;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
