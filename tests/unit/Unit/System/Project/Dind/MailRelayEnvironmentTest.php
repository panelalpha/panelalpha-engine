<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\EnvFile;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\AccountSecrets;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TestNetwork;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * Every project is handed the server's mail relay, so an application that sends mail has the address to send it to
 * instead of deriving one.
 */
class MailRelayEnvironmentTest extends TestCase
{
    private string $tmpRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        $this->tmpRoot = sys_get_temp_dir().'/pa-dind-mailrelay-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot.'/home/alice/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot.'/users/alice', 0777, true);
        $compose = "services:\n  app:\n    image: acme/app\n    env_file: .env\n";
        file_put_contents($this->projectDir.'/docker-compose.yml', $compose);
        file_put_contents($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE, $compose);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_a_generated_app_service_is_given_the_relay(): void
    {
        $decision = $this->strategy()->composeDecision(['runtime' => 'node', 'image' => 'node:22-slim']);
        $compose = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000, $decision));

        $this->assertSame(TestNetwork::container(1), $decision['env'][ComposeEnvironment::SMTP_HOST]);
        $this->assertSame('25', $decision['env'][ComposeEnvironment::SMTP_PORT]);
        $this->assertSame(
            TestNetwork::container(1),
            $compose['services']['app']['environment'][ComposeEnvironment::SMTP_HOST] ?? null
        );
        $this->assertSame('25', $compose['services']['app']['environment'][ComposeEnvironment::SMTP_PORT] ?? null);
    }

    public function test_the_accounts_own_value_of_either_name_cannot_replace_the_relay(): void
    {
        $decision = $this->strategy([
            ComposeEnvironment::SMTP_HOST => TestNetwork::public(9),
            ComposeEnvironment::SMTP_PORT => '2525',
            'APP_ENV' => 'prod',
        ])->composeDecision([]);

        $this->assertSame(TestNetwork::container(1), $decision['env'][ComposeEnvironment::SMTP_HOST]);
        $this->assertSame('25', $decision['env'][ComposeEnvironment::SMTP_PORT]);
        $this->assertSame('prod', $decision['env']['APP_ENV'], 'a name the engine does not set still reaches the app');
    }

    public function test_a_project_with_no_relay_named_gets_no_such_values(): void
    {
        $decision = $this->strategy([], [])->composeDecision(['env' => ['APP_ENV' => 'production']]);

        $this->assertArrayNotHasKey(ComposeEnvironment::SMTP_HOST, $decision['env']);
        $this->assertArrayNotHasKey(ComposeEnvironment::SMTP_PORT, $decision['env']);
        $this->assertSame('production', $decision['env']['APP_ENV']);
    }

    public function test_a_compose_projects_engine_env_file_carries_the_relay_over_the_accounts_own_key(): void
    {
        $model = $this->model(['env_vars' => [ComposeEnvironment::SMTP_HOST => TestNetwork::public(9)]]);

        $this->environment($model, $this->relay())->apply();

        $engine = $this->env($this->projectDir.'/'.EngineArtifacts::ENV_OVERRIDES);
        $this->assertSame(TestNetwork::container(1), $engine[ComposeEnvironment::SMTP_HOST]);
        $this->assertSame('25', $engine[ComposeEnvironment::SMTP_PORT]);
        $this->assertSame(TestNetwork::public(9), $this->env($this->projectDir.'/.env')[ComposeEnvironment::SMTP_HOST]);

        $run = Yaml::parseFile($this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE);
        $this->assertSame(
            ['.env', EngineArtifacts::ENV_OVERRIDES],
            $run['services']['app']['env_file'],
            'loaded after .env, so the relay wins over an account value of the same name'
        );
    }

    public function test_a_compose_project_without_a_relay_keeps_no_env_panelalpha(): void
    {
        $this->environment($this->model(), [])->apply();

        $this->assertFileDoesNotExist($this->projectDir.'/'.EngineArtifacts::ENV_OVERRIDES);
    }

    /** @param array<string, string> $envVars */
    private function strategy(array $envVars = [], ?array $relay = null): DeployStrategy
    {
        $model = new User;
        $model->username = 'alice';
        $model->setDetails(['env_vars' => $envVars]);

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);

        return new class($dind, $relay ?? $this->relay()) extends DeployStrategy
        {
            /** @param array<string, string> $relay */
            public function __construct(private Dind $stub, private array $relay)
            {
                parent::__construct($stub);
            }

            protected function mailRelay(): array
            {
                return $this->relay;
            }

            public function secrets(): AccountSecrets
            {
                return new class($this->stub) extends AccountSecrets
                {
                    public function for(string $purpose): string
                    {
                        return hash_hmac('sha256', $purpose, 'test');
                    }
                };
            }
        };
    }

    /** @return array<string, string> */
    private function relay(): array
    {
        return [ComposeEnvironment::SMTP_HOST => TestNetwork::container(1), ComposeEnvironment::SMTP_PORT => '25'];
    }

    /** @return array<string, string> */
    private function env(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $values = [];
        foreach (EnvFile::parse((string) file_get_contents($path)) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }

    /** @param array<string, string> $relay */
    private function environment(User $model, array $relay): Dind\ProjectEnvironment
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot.'/home'), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new class($runtime, $relay) extends Dind\ProjectEnvironment
        {
            /** @param array<string, string> $relay */
            public function __construct(Dind $dind, private array $relay)
            {
                parent::__construct($dind);
            }

            protected function envIsTracked(): bool
            {
                return false;
            }

            protected function mailRelay(): array
            {
                return $this->relay;
            }
        };
    }

    /** @param array<string, mixed> $details */
    private function model(array $details = []): User
    {
        $model = new class extends User
        {
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
            'deploy_strategy' => 'compose',
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
