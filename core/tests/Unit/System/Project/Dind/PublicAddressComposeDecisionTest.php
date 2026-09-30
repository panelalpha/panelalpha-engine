<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\AccountSecrets;
use PHPUnit\Framework\TestCase;

/**
 * engine#432: `${PA_PUBLIC_HOST}` in a laravel/php recipe's `env:` reached the
 * generated compose literally, and the container got ''. Every generated
 * compose goes through composeDecision(), so that is where it is resolved.
 */
class PublicAddressComposeDecisionTest extends TestCase
{
    private function strategy(?string $publicUrl): DeployStrategy
    {
        $model = new User();
        $model->username = 'alice';

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('publicAppUrl')->willReturn($publicUrl);

        return new class ($dind) extends DeployStrategy {
            public function __construct(private Dind $stub)
            {
                parent::__construct($stub);
            }

            public function secrets(): AccountSecrets
            {
                return new class ($this->stub) extends AccountSecrets {
                    public function userEnvVars(): array
                    {
                        return [];
                    }

                    // AccountSecrets::for() reads config('app.key').
                    public function for(string $purpose): string
                    {
                        return hash('sha256', $purpose);
                    }
                };
            }
        };
    }

    public function test_the_placeholders_are_resolved_against_the_accounts_address(): void
    {
        $decision = $this->strategy('https://crater.example.test')->composeDecision(['env' => [
            'SESSION_DOMAIN' => '${PA_PUBLIC_HOST}',
            'ASSET_ORIGIN' => '${PA_PUBLIC_URL}',
        ]]);

        $this->assertSame('crater.example.test', $decision['env']['SESSION_DOMAIN']);
        $this->assertSame('https://crater.example.test', $decision['env']['ASSET_ORIGIN']);
    }

    public function test_an_account_without_an_address_keeps_the_placeholder(): void
    {
        $decision = $this->strategy(null)->composeDecision(['env' => ['SESSION_DOMAIN' => '${PA_PUBLIC_HOST}']]);

        $this->assertSame('${PA_PUBLIC_HOST}', $decision['env']['SESSION_DOMAIN']);
    }
}
