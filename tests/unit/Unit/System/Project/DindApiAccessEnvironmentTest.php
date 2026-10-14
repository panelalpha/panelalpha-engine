<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\EnvFile;
use App\Lib\Project\ApiAccess;
use App\Models\Setting;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\TenantEgressGuard;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingFirewall;
use Tests\TestCase;

/**
 * An allowed project's application is handed the API address, the token and the
 * certificate: in the generated run file's environment, and in the env file the
 * repository's own compose reads. Another project gets nothing.
 */
class DindApiAccessEnvironmentTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    private string $homeRoot;

    private RecordingFirewall $firewall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://203-0-113-7.panelalpha.direct',
        ]);
        $this->app->forgetInstance('encrypter');
        Queue::fake();

        $this->firewall = new RecordingFirewall();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-api-access-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';

        foreach (['alice', 'bob'] as $username) {
            $this->makeUser($username, ['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
            $this->projectDir($username);
        }
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_the_environment_carries_the_engine_address_and_token_of_an_allowed_project(): void
    {
        $entry = $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);
        file_put_contents($this->projectDir('alice') . '/' . EngineArtifacts::RUN_COMPOSE, "services:\n  app:\n    image: acme/app\n    env_file: .env\n");

        $this->environment('alice')->apply();

        $vars = $this->vars((string) file_get_contents($this->projectDir('alice') . '/' . EngineArtifacts::ENV_OVERRIDES));
        $this->assertSame('https://203-0-113-7.panelalpha.direct:2011/api', $vars[ApiAccess::ENV_URL]);
        $this->assertStringStartsWith((string) $entry['token_id'] . '|', $vars[ApiAccess::ENV_TOKEN]);
        $this->assertArrayNotHasKey(ApiAccess::ENV_CA, $vars, 'the engine holds no certificate in this test');

        $compose = Yaml::parseFile($this->projectDir('alice') . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertSame(['.env', EngineArtifacts::ENV_OVERRIDES], $compose['services']['app']['env_file']);
    }

    public function test_a_project_that_is_not_allowed_is_handed_nothing(): void
    {
        $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $this->environment('bob')->apply();

        $this->assertFileDoesNotExist($this->projectDir('bob') . '/' . EngineArtifacts::ENV_OVERRIDES);
    }

    public function test_a_generated_run_file_carries_the_values_for_an_allowed_project_only(): void
    {
        $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $alice = $this->strategy('alice')->composeDecision(['env' => ['APP_ENV' => 'production']]);
        $bob = $this->strategy('bob')->composeDecision(['env' => ['APP_ENV' => 'production']]);

        $this->assertSame('https://203-0-113-7.panelalpha.direct:2011/api', $alice['env'][ApiAccess::ENV_URL]);
        $this->assertArrayHasKey(ApiAccess::ENV_TOKEN, $alice['env']);
        $this->assertSame('production', $alice['env']['APP_ENV']);
        $this->assertArrayNotHasKey(ApiAccess::ENV_URL, $bob['env']);
        $this->assertArrayNotHasKey(ApiAccess::ENV_TOKEN, $bob['env']);
    }

    /** The account cannot set the engine's own variables through its env_vars. */
    public function test_the_accounts_own_variables_cannot_replace_the_engines(): void
    {
        $this->user('alice')->setDetails(['env_vars' => [ApiAccess::ENV_TOKEN => 'mine']]);
        $this->access()->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);

        $decision = $this->strategy('alice')->composeDecision([]);

        $this->assertStringStartsWith('1|', (string) $decision['env'][ApiAccess::ENV_TOKEN]);
        $this->assertNotSame('mine', $decision['env'][ApiAccess::ENV_TOKEN]);
    }

    public function test_a_token_in_the_decision_cannot_be_interpolated_by_compose(): void
    {
        $this->assertSame(
            ['PANELALPHA_ENGINE_TOKEN' => 'a$$b'],
            ComposeEnvironment::escapedForCompose(['PANELALPHA_ENGINE_TOKEN' => 'a$b']),
        );
    }

    /**
     * An allowed account's own egress guard has to admit the API port, and a denial
     * has to take it back out: the account's service re-applies the file either way.
     */
    public function test_the_accounts_egress_guard_follows_its_allowance(): void
    {
        $guard = $this->tmpRoot . '/users/alice/entrypoint.d/' . TenantEgressGuard::FILE;
        mkdir(dirname($guard), 0777, true);
        file_put_contents($guard, 'stale');

        $runtime = $this->dindRuntime('alice');
        $access = $this->access();

        $access->allow($this->user('alice'), ApiAccess::SCOPE_SELF, false);
        $runtime->rewriteEgressGuard();
        $this->assertStringContainsString(
            'HOST_PORTS="25 80 443 ' . TenantEgressGuard::API_PORT . '"',
            (string) file_get_contents($guard)
        );

        $access->deny($this->user('alice'));
        $runtime->rewriteEgressGuard();
        $this->assertStringContainsString('HOST_PORTS="25 80 443"', (string) file_get_contents($guard));
    }

    private function dindRuntime(string $username): Dind
    {
        $runtime = (new ProjectAggregate($this->engineSystem(), $this->user($username)))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function engineSystem(): LocalHostSystem
    {
        return new LocalHostSystem($this->tmpRoot, $this->homeRoot);
    }

    private function access(): ApiAccess
    {
        return new ApiAccess(firewall: $this->firewall);
    }



    private function user(string $username): User
    {
        $user = User::findByUsername($username);
        $this->assertNotNull($user);

        return $user;
    }

    private function projectDir(string $username): string
    {
        $dir = $this->homeRoot . '/' . $username . '/project';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            file_put_contents($dir . '/.env.example', "APP_NAME=Demo\n");
        }

        return $dir;
    }

    private function environment(string $username): Dind\ProjectEnvironment
    {
        $model = $this->user($username);
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return new class ($runtime) extends Dind\ProjectEnvironment {
            protected function envIsTracked(): bool
            {
                return true;
            }
        };
    }

    private function strategy(string $username): DeployStrategy
    {
        $model = new User();
        $model->username = $username;
        $model->setDetails($this->user($username)->getDetails());

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn(new LocalHostSystem($this->tmpRoot, $this->homeRoot));

        return new class ($dind) extends DeployStrategy {
            public function __construct(private Dind $stub)
            {
                parent::__construct($stub);
            }

            public function secrets(): AccountSecrets
            {
                return new class ($this->stub) extends AccountSecrets {
                    public function __construct(private Dind $d)
                    {
                        parent::__construct($d);
                    }

                    public function userEnvVars(): array
                    {
                        return $this->d->userModel()->getEnvVars();
                    }
                };
            }
        };
    }

    /** @return array<string, string> */
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
