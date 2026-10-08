<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\AccountSecrets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every account's app is at `/app`, so a secret an app derives
 * from its own path is the same on every tenant. The platform hands each
 * account a stable secret of its own instead.
 */
class InstanceSecretTest extends TestCase
{
    /** @param array<string, string> $envVars */
    private function strategy(string $username, array $envVars = [], string $appKey = 'base64:engine-key'): DeployStrategy
    {
        $model = new User();
        $model->username = $username;

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);

        return new class ($dind, $appKey, $envVars) extends DeployStrategy {
            /** @param array<string, string> $envVars */
            public function __construct(private Dind $stub, private string $appKey, private array $envVars)
            {
                parent::__construct($stub);
            }

            public function secrets(): AccountSecrets
            {
                return new class ($this->stub, $this->appKey, $this->envVars) extends AccountSecrets {
                    /** @param array<string, string> $envVars */
                    public function __construct(private Dind $d, private string $key, private array $envVars)
                    {
                        parent::__construct($d);
                    }

                    public function userEnvVars(): array
                    {
                        return $this->envVars;
                    }

                    // AccountSecrets::for() reads config('app.key'), which a
                    // bare PHPUnit process has not bootstrapped.
                    public function for(string $purpose): string
                    {
                        return hash_hmac('sha256', $purpose . ':' . $this->d->userModel()->username, $this->key);
                    }
                };
            }
        };
    }

    public function test_every_generated_app_gets_a_secret_of_its_own_account(): void
    {
        $alice = $this->strategy('alice')->composeDecision(['env' => ['APP_ENV' => 'production']]);
        $bob = $this->strategy('bob')->composeDecision(['env' => ['APP_ENV' => 'production']]);

        $secret = $alice['env'][ComposeEnvironment::INSTANCE_SECRET];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertNotSame($secret, $bob['env'][ComposeEnvironment::INSTANCE_SECRET]);
        $this->assertSame('production', $alice['env']['APP_ENV']);
    }

    public function test_it_is_stable_across_deploys_and_not_any_other_generated_secret(): void
    {
        $first = $this->strategy('alice')->composeDecision([]);
        $again = $this->strategy('alice')->composeDecision([]);
        $secrets = $this->strategy('alice')->secrets();

        $this->assertArrayHasKey('PA_INSTANCE_SECRET', $first['env']);
        $this->assertSame($first['env']['PA_INSTANCE_SECRET'], $again['env']['PA_INSTANCE_SECRET']);
        $this->assertNotSame($secrets->for('compose-placeholders'), $first['env']['PA_INSTANCE_SECRET']);
        $this->assertNotSame($secrets->generatedSecretKeyBase(), $first['env']['PA_INSTANCE_SECRET']);
    }

    public function test_the_accounts_env_vars_cannot_replace_it(): void
    {
        $decision = $this->strategy('alice', ['PA_INSTANCE_SECRET' => 'mine', 'APP_ENV' => 'prod'])->composeDecision([]);

        $this->assertArrayHasKey('PA_INSTANCE_SECRET', $decision['env']);
        $this->assertNotSame('mine', $decision['env']['PA_INSTANCE_SECRET']);
        $this->assertSame('prod', $decision['env']['APP_ENV']);
    }

    public function test_it_reaches_the_generated_app_service(): void
    {
        $decision = $this->strategy('alice')->composeDecision(['runtime' => 'node', 'image' => 'node:22-slim']);
        $compose = Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000, $decision));

        $this->assertArrayHasKey('PA_INSTANCE_SECRET', $decision['env']);
        $this->assertSame(
            $decision['env']['PA_INSTANCE_SECRET'],
            $compose['services']['app']['environment']['PA_INSTANCE_SECRET'] ?? null
        );
    }

    public function test_it_reaches_a_php_app_the_way_kirby_and_atheos_are_deployed(): void
    {
        $decision = $this->strategy('alice')->composeDecision([
            'runtime' => 'php',
            'image' => 'panelalpha/php:8.3-apache-bookworm',
            'env' => ['PA_DOCROOT' => '/app'],
        ]);
        $compose = Yaml::parse(DeployCompose::framework($decision, 8000, 'https://alice.example.test'));

        $this->assertArrayHasKey('PA_INSTANCE_SECRET', $decision['env']);
        $this->assertSame(
            $decision['env']['PA_INSTANCE_SECRET'],
            $compose['services']['app']['environment']['PA_INSTANCE_SECRET'] ?? null
        );
    }
}
