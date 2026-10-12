<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppDatabase;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\Strategy\AccountSecrets;
use Tests\TestCase;

/**
 * A database created by hand and pointed at the host `mysql_server_info`
 * reports cannot be reached from inside the account: the app container is in
 * the account's nested Docker, several NATs away, and resolves none of the
 * engine's names. The engine pins that host when it provisions the database
 * itself; the same pin has to appear when the app names the host in its env,
 * so the "create a database, point the app at it" flow works on DinD too.
 */
class DatabaseHostPinnedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['env.USERS_DB_HOST' => 'database-users.shared-hosting.palocal']);
    }

    private function strategy(): DeployStrategy
    {
        $model = new User();
        $model->username = 'alice';

        $system = $this->createStub(System::class);
        $system->method('exec')->willReturn((string) json_encode([
            \App\Lib\Deploy\Dind\TenantNetwork::NAME => ['IPAddress' => '10.10.0.5', 'IPPrefixLen' => 16],
        ]));

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('publicAppUrl')->willReturn(null);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('alice');

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

                    public function for(string $purpose): string
                    {
                        return hash('sha256', $purpose);
                    }
                };
            }
        };
    }

    public function test_the_reported_host_is_pinned_into_extra_hosts(): void
    {
        $decision = $this->strategy()->composeDecision(['env' => [
            'DB_HOST' => AppDatabase::hostname(),
            'DB_NAME' => 'alice_shop',
        ]]);

        $this->assertSame(['database-users.shared-hosting.palocal:10.10.0.2'], $decision['extra_hosts']);
    }

    /** Nothing names the database host: no pin is added. */
    public function test_a_decision_that_does_not_name_the_host_is_untouched(): void
    {
        $decision = $this->strategy()->composeDecision(['env' => ['APP_ENV' => 'production']]);

        $this->assertArrayNotHasKey('extra_hosts', $decision);
    }

    /** A pin a strategy already made is kept, one entry per host name. */
    public function test_a_pin_already_present_is_not_duplicated(): void
    {
        $decision = $this->strategy()->composeDecision([
            'env' => ['DB_HOST' => AppDatabase::hostname()],
            'extra_hosts' => [AppDatabase::hostname() . ':10.10.0.9'],
        ]);

        $this->assertSame([AppDatabase::hostname() . ':10.10.0.9'], $decision['extra_hosts']);
    }
}
