<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A checkout change asks a just-started account's dockerd which containers run
 * ({@see \App\System\Project\Deployment\DindDeployMechanics::syncHostingForCheckoutRebuild()}), so the start waits for it.
 */
class AccountStartAwaitsInnerDockerTest extends TestCase
{
    public function test_an_account_start_returns_once_its_inner_dockerd_answers(): void
    {
        config(['env.DIND_RUNTIME' => 'privileged']);
        $host = new class extends System {
            /** @var list<string> */
            public array $commands = [];

            public function __construct()
            {
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = is_array($cmd) ? implode(' ', LocalRoot::plain($cmd)) : $cmd;

                return FakeProcess::ok();
            }
        };
        $user = new ModelsUser();
        $user->username = 'alice';
        $user->setDetails(['template' => 'dind']);

        (new Project($host, $user))->up();

        $up = array_key_last(array_filter($host->commands, static fn (string $c): bool => str_contains($c, ' up -d')));
        $probe = array_key_last(array_filter($host->commands, static fn (string $c): bool => str_contains($c, 'account-docker.ping -- alice')));
        $this->assertNotNull($up, implode("\n", $host->commands));
        $this->assertNotNull($probe, 'the start returned without asking the inner dockerd');
        $this->assertGreaterThan($up, $probe, 'the inner dockerd was asked before the account was up');
    }
}
