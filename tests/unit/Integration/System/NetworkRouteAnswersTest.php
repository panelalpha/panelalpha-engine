<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\System;
use App\System\Network;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** Route answers from the host that cannot be read are errors, never a guess. */
class NetworkRouteAnswersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetRoutes();
    }

    protected function tearDown(): void
    {
        $this->forgetRoutes();
        parent::tearDown();
    }

    public function test_a_default_route_without_a_gateway_cannot_be_parsed(): void
    {
        $network = new Network($this->answering(FakeProcess::ok("default dev eth0 proto static\n")));

        $this->expectExceptionMessage("Unable to parse default route for IPv6: default dev eth0 proto static\n");
        $network->defaultRoute(6);
    }

    public function test_routes_that_cannot_be_read_are_an_error(): void
    {
        $network = new Network($this->answering(FakeProcess::failed('RTNETLINK answers: Operation not permitted')));

        $this->expectExceptionMessage("Could not read the host's routes: RTNETLINK answers: Operation not permitted");
        $network->hasRoute(TestNetwork::public(7), 32, 4);
    }

    private function answering(Process $answer): System
    {
        return new class ($answer) extends System {
            public function __construct(private Process $answer)
            {
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->answer;
            }
        };
    }

    private function forgetRoutes(): void
    {
        foreach (['defaultIpv4Route', 'defaultIpv6Route'] as $property) {
            (new \ReflectionProperty(Network::class, $property))->setValue(null, null);
        }
    }
}
