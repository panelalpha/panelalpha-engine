<?php

namespace Tests\Unit\Deploy\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\ShellOperations;
use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Before a host build the network is made if missing and its
 * firewall applied. Neither may fail a deploy: without them the build runs as
 * it did before the network existed, with a warning.
 */
class HostCompileBuildNetworkTest extends TestCase
{
    /**
     * @param list<string> $failing argv prefixes (space-joined) that throw, each once
     */
    private function system(array $failing): System
    {
        return new class ($failing) extends System {
            /** @var list<string> */
            public array $ran = [];

            /** @param list<string> $failing */
            public function __construct(private array $failing)
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->ran[] = $line;
                foreach ($this->failing as $i => $prefix) {
                    if (str_starts_with($line, $prefix)) {
                        unset($this->failing[$i]);
                        throw new \Exception("failed: $prefix");
                    }
                }

                return '';
            }
        };
    }

    private function prepare(System $system, ?string $network): HostCompile
    {
        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder(null, '', $network));

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        $compile = new HostCompile($dind);
        (new ReflectionMethod(HostCompile::class, 'prepareBuildNetwork'))->invoke($compile);

        return $compile;
    }

    private function inspect(): string
    {
        return implode(' ', BuildNetwork::inspectArgv('panelalpha-build'));
    }

    private function create(): string
    {
        return implode(' ', BuildNetwork::createArgv('panelalpha-build'));
    }

    private function firewall(): string
    {
        return implode(' ', BuildNetwork::firewallArgv('panelalpha-build'));
    }

    public function test_a_missing_network_is_created_then_firewalled(): void
    {
        $system = $this->system([$this->inspect()]);
        $this->prepare($system, 'panelalpha-build');

        $this->assertSame([$this->inspect(), $this->create(), $this->firewall()], $system->ran);
    }

    public function test_an_existing_network_is_only_firewalled(): void
    {
        $system = $this->system([]);
        $this->prepare($system, 'panelalpha-build');

        $this->assertSame([$this->inspect(), $this->firewall()], $system->ran);
    }

    public function test_a_concurrent_create_is_not_an_error(): void
    {
        // inspect misses, create loses the race, the second inspect finds it.
        $system = $this->system([$this->inspect(), $this->create()]);
        $this->prepare($system, 'panelalpha-build');

        $this->assertSame([$this->inspect(), $this->create(), $this->inspect(), $this->firewall()], $system->ran);
    }

    public function test_a_network_that_cannot_be_created_falls_back_to_the_default_bridge(): void
    {
        // What a host answers once a firewall flush has removed Docker's chains.
        $system = $this->system([$this->inspect(), $this->create(), $this->inspect()]);
        $compile = $this->prepare($system, 'panelalpha-build');

        $this->assertSame([$this->inspect(), $this->create(), $this->inspect()], $system->ran);
        $builder = (new ReflectionMethod(HostCompile::class, 'hostBuilder'))->invoke($compile);
        $this->assertInstanceOf(DindHostBuilder::class, $builder);
        $this->assertNull($builder->network(), 'this build goes to the default bridge rather than failing');
        $this->assertNotContains('--network', $builder->composerInstallArgv(new EngineAccount('acme', '/home/acme', '1001:1001')));
    }

    public function test_a_firewall_that_cannot_be_applied_does_not_stop_the_build(): void
    {
        $system = $this->system([$this->firewall()]);
        $this->prepare($system, 'panelalpha-build');

        $this->assertSame([$this->inspect(), $this->firewall()], $system->ran);
    }

    public function test_nothing_runs_when_the_operator_opted_out(): void
    {
        $system = $this->system([]);
        $this->prepare($system, '');

        $this->assertSame([], $system->ran);
    }
}
