<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Images;
use Tests\TestCase;

/**
 * engine#246: a host build ran the customer's scripts on Docker's default
 * bridge, from where it reached the engine API (bridge gateway and public
 * address), the host's private network and 169.254.169.254. Builds now run on
 * a network the firewall script limits to the internet.
 */
class BuildNetworkTest extends TestCase
{
    private function account(): EngineAccount
    {
        return new EngineAccount('acme', '/home/acme', '1001:1002');
    }

    /** @return array<string, list<string>> */
    private function allArgv(DindHostBuilder $builder): array
    {
        return [
            'node' => $builder->nodeBuildArgv($this->account(), Images::NODE_IMAGE, 'npm ci', 'npm run build'),
            'php' => $builder->phpBuildArgv($this->account(), 'panelalpha/php:8.3-cli-bookworm-pa1', 'composer install'),
            'composer' => $builder->composerInstallArgv($this->account()),
        ];
    }

    public function test_every_host_build_runs_on_the_build_network(): void
    {
        $builder = new DindHostBuilder(null, '', 'panelalpha-build');

        foreach ($this->allArgv($builder) as $kind => $argv) {
            $at = array_search('--network', $argv, true);
            $this->assertNotFalse($at, "$kind build has no --network");
            $this->assertSame('panelalpha-build', $argv[$at + 1], $kind);
            // A docker run option, so it has to come before the image.
            $image = $kind === 'composer' ? Images::COMPOSER_IMAGE : ($kind === 'node' ? Images::NODE_IMAGE : 'panelalpha/php:8.3-cli-bookworm-pa1');
            $this->assertLessThan(array_search($image, $argv, true), $at, $kind);
        }
    }

    public function test_the_engine_puts_builds_on_it_by_default(): void
    {
        config(['deploy.build_network' => 'panelalpha-build']);
        $this->assertSame('panelalpha-build', (new DindEngine())->hostBuilder()->network());
    }

    public function test_an_empty_setting_opts_out_to_the_default_bridge(): void
    {
        config(['deploy.build_network' => '']);
        $builder = (new DindEngine())->hostBuilder();

        $this->assertNull($builder->network());
        foreach ($this->allArgv($builder) as $kind => $argv) {
            $this->assertNotContains('--network', $argv, $kind);
        }
    }

    public function test_a_name_docker_would_refuse_falls_back_to_the_default(): void
    {
        $this->assertSame(BuildNetwork::DEFAULT_NAME, BuildNetwork::resolve('bad name; rm -rf /'));
        $this->assertSame('my-build.net_1', BuildNetwork::resolve(' my-build.net_1 '));
        $this->assertNull(BuildNetwork::resolve('  '));
    }

    public function test_the_network_has_a_fixed_bridge_and_leaves_br_netfilter_alone(): void
    {
        $argv = BuildNetwork::createArgv('panelalpha-build');

        $this->assertSame(['sudo', 'docker', 'network', 'create'], array_slice($argv, 0, 4));
        $this->assertContains('com.docker.network.bridge.name=br-pa-build', $argv);
        // icc=false would make Docker load br_netfilter host-wide.
        $this->assertNotContains('com.docker.network.bridge.enable_icc=false', $argv);
        $this->assertSame('panelalpha-build', $argv[array_key_last($argv)]);
        // br- so engine#241's DNAT for :2011 leaves it to docker-proxy like any bridge.
        $this->assertStringStartsWith('br-', BuildNetwork::BRIDGE);
        $this->assertLessThanOrEqual(15, strlen(BuildNetwork::BRIDGE), 'Linux interface names stop at 15');
    }

    public function test_the_firewall_runs_in_the_host_namespace(): void
    {
        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'sh', BuildNetwork::FIREWALL_SCRIPT, 'panelalpha-build'],
            BuildNetwork::firewallArgv('panelalpha-build')
        );
    }

    public function test_the_firewall_script_refuses_the_host_and_every_private_range(): void
    {
        $path = __DIR__ . '/../../../../../scripts/build-network-firewall.sh';
        if (!is_file($path)) {
            $this->markTestSkipped('scripts/ is not beside core/ here');
        }
        $script = (string) file_get_contents($path);
        $this->assertSame(basename(BuildNetwork::FIREWALL_SCRIPT), basename($path));

        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '100.64.0.0/10', '127.0.0.0/8'] as $cidr) {
            $this->assertStringContainsString($cidr, $script);
        }
        // The host itself, on any of its addresses, including the public one.
        $this->assertStringContainsString('iptables -I INPUT -i "$bridge" -j REJECT', $script);
        // The chain Docker evaluates first and keeps across a daemon restart.
        $this->assertStringContainsString('for parent in DOCKER-USER FORWARD', $script);
        // Egress still works after CSF has removed Docker's own NAT.
        $this->assertStringContainsString('-j MASQUERADE', $script);

        $csf = (string) file_get_contents(dirname($path) . '/csf.sh');
        $this->assertStringContainsString('panelalpha-build-network', $csf);
        $this->assertStringContainsString('build-network-firewall.sh panelalpha-build', $csf);
    }
}
