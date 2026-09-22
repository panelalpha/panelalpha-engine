<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\EnvFile;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DindProjectEnvironmentTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-env-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->projectDir = $this->homeRoot . '/alice/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_apply_writes_env_from_example_and_fills_blank_app_key(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nAPP_KEY=\nAPP_DEBUG=true\n");

        $model = $this->dindModel();
        $this->dind($model)->applyProjectEnvVars();

        $this->assertFileExists($this->projectDir . '/.env.default');
        $this->assertFileExists($this->projectDir . '/.env');

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('Demo', $env['APP_NAME']);
        $this->assertNotSame('', $env['APP_KEY']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertFalse($model->usedCustomEnvVars());
    }

    public function test_apply_merges_non_empty_overrides_onto_example_base(): void
    {
        file_put_contents($this->projectDir . '/.env.example', "APP_NAME=Demo\nAPP_KEY=\n");

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom', 'EMPTY' => '']]);
        $this->dind($model)->applyProjectEnvVars();

        $default = $this->vars((string) file_get_contents($this->projectDir . '/.env.default'));
        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));

        $this->assertSame('Demo', $default['APP_NAME']);
        $this->assertSame('Custom', $env['APP_NAME']);
        $this->assertArrayNotHasKey('EMPTY', $env);
        $this->assertTrue($model->usedCustomEnvVars());
    }

    public function test_apply_creates_the_env_file_a_generated_run_file_names(): void
    {
        // A PHP-plain archive: no .env, no .env.example, no compose file of its own.
        // The engine's run file still names `.env`, and compose refuses to start without it.
        file_put_contents(
            $this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE,
            "services:
  app:
    image: php:8-cli
    env_file:
      - .env
"
        );

        $this->dind($this->dindModel())->applyProjectEnvVars();

        $this->assertFileExists($this->projectDir . '/.env');
        $this->assertSame('', file_get_contents($this->projectDir . '/.env'));
    }

    /**
     * ADR-0001 D3: a tracked `.env` is the client's. The overrides go to
     * `.env.panelalpha` instead, attached only to the service whose own
     * `env_file` already loads `.env` -- not the database sidecar, which
     * never did.
     */
    public function test_tracked_env_stays_untouched_and_overrides_go_to_env_panelalpha(): void
    {
        $envContents = "APP_NAME=Demo\nAPP_KEY=base64:committedbytheclient\n";
        file_put_contents($this->projectDir . '/.env', $envContents);
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
          db:
            image: mariadb
            env_file: .env.db
        YAML);

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]);
        $this->forcedEnvironment($model, tracked: true)->apply();

        $this->assertSame($envContents, file_get_contents($this->projectDir . '/.env'));

        $overrides = $this->vars((string) file_get_contents(
            $this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES
        ));
        $this->assertSame('Custom', $overrides['APP_NAME']);

        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertSame(['.env', EngineArtifacts::ENV_OVERRIDES], $compose['services']['app']['env_file']);
        $this->assertSame('.env.db', $compose['services']['db']['env_file']);
        $this->assertTrue($model->usedCustomEnvVars());
    }

    /**
     * The pre-ADR-0001 behaviour: an untracked `.env` (or no git at all) is
     * still merged in place, and no `.env.panelalpha` is created.
     */
    public function test_untracked_env_is_merged_as_before(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\nAPP_KEY=base64:generated\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $model = $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]);
        $this->forcedEnvironment($model, tracked: false)->apply();

        $env = $this->vars((string) file_get_contents($this->projectDir . '/.env'));
        $this->assertSame('Custom', $env['APP_NAME']);
        $this->assertFileDoesNotExist($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);

        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertSame('.env', $compose['services']['app']['env_file']);
    }

    /**
     * Clearing every env_vars field is itself a redeploy: the next apply()
     * has to remove `.env.panelalpha` and detach it from the run file again,
     * not leave a stale override in force.
     */
    public function test_removing_all_env_vars_removes_env_panelalpha_and_its_env_file_entry(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_NAME=Demo\n");
        file_put_contents($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE, <<<'YAML'
        services:
          app:
            image: acme/app
            env_file: .env
        YAML);

        $this->forcedEnvironment(
            $this->dindModel(['env_vars' => ['APP_NAME' => 'Custom']]),
            tracked: true
        )->apply();
        $this->assertFileExists($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);

        $this->forcedEnvironment($this->dindModel(['env_vars' => []]), tracked: true)->apply();

        $this->assertFileDoesNotExist($this->projectDir . '/' . EngineArtifacts::ENV_OVERRIDES);
        $compose = Yaml::parseFile($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        // detach() drops the entry rather than collapsing a lone survivor back
        // to a bare scalar, so the round trip leaves a one-item list.
        $this->assertSame(['.env'], $compose['services']['app']['env_file']);
    }

    public function test_defer_to_compose_defaults_matches_lib_lychee_case(): void
    {
        $envExample = <<<'ENV'
APP_NAME=Lychee
APP_KEY=base64:generatedbytheengine
DB_CONNECTION=sqlite
DB_HOST=
DB_DATABASE=lychee
ENV;
        $compose = <<<'YAML'
x-common-env: &common-env
  APP_KEY: "${APP_KEY}"
  DB_CONNECTION: "${DB_CONNECTION:-mysql}"
  DB_HOST: "${DB_HOST:-lychee_db}"
  DB_DATABASE: "${DB_DATABASE:-lychee}"
YAML;

        [$trimmed, $dropped] = Dind\ProjectEnvironment::deferToComposeDefaults($envExample, $compose);

        $this->assertContains('DB_CONNECTION', $dropped);
        $this->assertArrayNotHasKey('DB_CONNECTION', $this->vars($trimmed));
        $this->assertSame('Lychee', $this->vars($trimmed)['APP_NAME']);
    }

    /**
     * @return array<string, string>
     */
    private function vars(string $contents): array
    {
        $values = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /**
     * A `ProjectEnvironment` whose tracked/untracked decision is forced
     * rather than answered by a real git repository: `GitRepository` only
     * runs through a live DinD shell, which nothing here provides.
     */
    private function forcedEnvironment(ModelsUser $model, bool $tracked): Dind\ProjectEnvironment
    {
        return new class($this->dind($model), $tracked) extends Dind\ProjectEnvironment {
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

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
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
