<?php

namespace Tests\Integration\System\Privileged;

use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

final class RootAccountComposeTest extends TestCase
{
    public function test_the_broker_takes_it_as_a_client_it_runs_itself(): void
    {
        $run = Privileged::accountCompose('alice')->run(['compose', 'ps']);

        $this->assertSame(['pa-root', ...array_slice($run, 1)], LocalRoot::plain($run));
    }

    public function test_the_client_environment_is_the_one_the_brokers_sandbox_starts_the_client_with(): void
    {
        $script = 'import json; from types import SimpleNamespace; from rootbroker import sandbox; '
            .'print(json.dumps(sandbox.environment(SimpleNamespace(home="/home/alice", name="alice"), '
            .'{"PWD": "/home/alice/project", "DOCKER_CONFIG": "/home/alice/.panelalpha-docker"}, "/run/relay")))';
        exec('PYTHONPATH='.escapeshellarg(dirname(LocalRoot::client(), 2)).' python3 -c '.escapeshellarg($script), $output, $status);
        $this->assertSame(0, $status);
        $broker = json_decode(implode("\n", $output), true);

        $environment = Privileged::accountCompose('alice')->clientEnvironment(
            '/home/alice/',
            ['PWD' => '/home/alice/project', 'DOCKER_CONFIG' => '/home/alice/.panelalpha-docker'],
        );

        ksort($broker);
        ksort($environment);
        $this->assertSame($broker, $environment);
    }
}
