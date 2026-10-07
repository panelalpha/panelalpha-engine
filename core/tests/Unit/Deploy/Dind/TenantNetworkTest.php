<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\TenantNetwork;
use Tests\TestCase;

/**
 * Accounts left pash-default-network for a network whose policy the
 * host enforces. The compose file, the firewall script and the address the
 * engine hands an app for sites-db must agree on the pinned addresses.
 */
class TenantNetworkTest extends TestCase
{
    private function repo(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 5) . '/' . $path);
    }

    public function test_the_refused_ranges_are_the_firewall_scripts(): void
    {
        $this->assertSame(1, preg_match('/^REFUSED="([^"]*)"$/m', $this->repo('scripts/tenant-network-firewall.sh'), $m));
        $this->assertSame(explode(' ', $m[1]), TenantNetwork::REFUSED_RANGES);
    }

    public function test_refuses_private_and_reserved_addresses_only(): void
    {
        foreach (['10.10.0.25', '127.0.0.1', '169.254.169.254', '172.20.0.1', '192.168.1.10', '100.64.0.1'] as $ip) {
            $this->assertTrue(TenantNetwork::refuses($ip), $ip);
        }
        foreach (['140.82.121.4', '8.8.8.8', '203.0.113.45', 'github.com', '::1'] as $ip) {
            $this->assertFalse(TenantNetwork::refuses($ip), $ip);
        }
    }

    public function test_sites_db_address_is_the_pinned_one_on_the_tenant_network(): void
    {
        $json = json_encode([
            'pash-tenants' => ['IPAddress' => '10.200.128.7', 'IPPrefixLen' => 16],
        ]);

        $this->assertSame('10.200.0.2', TenantNetwork::sitesDbAddressFor((string) $json));
    }

    public function test_an_account_still_on_the_old_network_gets_no_tenant_address(): void
    {
        $json = json_encode([
            'pash-default-network' => ['IPAddress' => '172.25.0.15', 'IPPrefixLen' => 24],
        ]);

        $this->assertNull(TenantNetwork::sitesDbAddressFor((string) $json));
        $this->assertNull(TenantNetwork::sitesDbAddressFor(''));
        $this->assertNull(TenantNetwork::sitesDbAddressFor('null'));
        $this->assertNull(TenantNetwork::sitesDbAddressFor((string) json_encode([
            'pash-tenants' => ['IPAddress' => '', 'IPPrefixLen' => 0],
        ])));
    }

    public function test_an_accounts_compose_changes_only_in_the_network_it_names(): void
    {
        $compose = "services:\n  dind:\n    container_name: alice\n    hostname: alice\n"
            . "    volumes:\n      - ./supervisord.conf:/etc/supervisor/supervisord.conf\n"
            . "networks:\n  default:\n    name: pash-default-network\n    external: true\n";

        $moved = TenantNetwork::composeOnTenantNetwork($compose);

        $this->assertSame(str_replace('name: pash-default-network', 'name: pash-tenants', $compose), $moved);
        $this->assertSame($moved, TenantNetwork::composeOnTenantNetwork($moved));
    }

    public function test_the_firewall_creates_the_network_from_the_host_namespace(): void
    {
        $this->assertSame(
            ['sudo', 'nsenter', '--target', '1', '--all', 'sh', TenantNetwork::FIREWALL_SCRIPT, '--create'],
            TenantNetwork::firewallArgv()
        );
    }

    public function test_compose_firewall_and_engine_pin_the_same_addresses(): void
    {
        $compose = $this->repo('docker-compose.yml');
        $script = $this->repo('scripts/tenant-network-firewall.sh');

        foreach (['2' => 'SITES_DB', '3' => 'CACHE_REGISTRY', '4' => 'REGISTRY_PROXY'] as $host => $var) {
            $this->assertStringContainsString('ipv4_address: ${TENANT_NETWORK_PREFIX:-10.200}.0.' . $host, $compose);
            $this->assertStringContainsString($var . '="$prefix.0.' . $host . '"', $script);
        }
        // Compose's default is the script's first candidate.
        $this->assertStringContainsString('for b in $(seq 200 219)', $script);
        // The rules follow the network that exists, not a prefix edited later.
        $this->assertStringContainsString("docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}'", $script);
        $this->assertStringContainsString('prefix=$actual', $script);
        $this->assertStringContainsString('name: ' . TenantNetwork::NAME, $compose);
    }

    public function test_the_firewall_script_holds_accounts_to_the_shared_services(): void
    {
        $script = $this->repo('scripts/tenant-network-firewall.sh');

        $this->assertStringContainsString('com.docker.network.bridge.enable_icc=false', $script);
        $this->assertStringContainsString('-p tcp --dport 3306 -j ACCEPT', $script);
        $this->assertStringContainsString('-p tcp --dport 5000 -j ACCEPT', $script);
        // Account to account, and anything else on the bridge, after the allows.
        $this->assertStringContainsString('-A PA-TENANT-NET -o $BRIDGE -j DROP', $script);
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16'] as $cidr) {
            $this->assertStringContainsString($cidr, $script);
        }
        // The host: mail and the sites only.
        $this->assertStringContainsString('--dports 25,80,443 -j ACCEPT', $script);
        $this->assertStringContainsString('for parent in DOCKER-USER FORWARD', $script);
        // Refilled in one transaction, never left empty.
        $this->assertStringContainsString('iptables-restore -w 30 --noflush', $script);
        // The proxy on the host reaches any app port past CSF's TCP_OUT.
        $this->assertStringContainsString('iptables -I OUTPUT -o "$BRIDGE" -j ACCEPT', $script);
        // Accounts take the upper half; the lower one holds the pinned services.
        $this->assertStringContainsString('--ip-range "$1.128.0/17"', $script);
    }

    /**
     * The prefix is picked where nothing on the host uses it, recorded for
     * docker-compose.yml, and a clash is refused rather than routed over.
     * scripts/tenant-network-firewall.test.sh runs the script against fakes.
     */
    public function test_the_prefix_avoids_networks_the_host_already_uses(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not installed');
        }
        $root = dirname(__DIR__, 5);

        exec(escapeshellarg($bash) . ' ' . escapeshellarg($root . '/scripts/tenant-network-firewall.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertContains('All passed.', $out);
    }

    /**
     * A port of the bridge may speak only as its own container,
     * and the binding follows containers Docker attaches on its own.
     */
    public function test_ports_are_bound_to_their_containers_and_kept_bound(): void
    {
        $script = $this->repo('scripts/tenant-network-firewall.sh');
        $this->assertStringContainsString('table bridge pa_tenants', $script);
        $this->assertStringContainsString('iifname . ether saddr . arp saddr ether . arp saddr ip @arp_ok accept', $script);
        $this->assertStringContainsString('iifname . ether saddr . ip saddr @ip_ok accept', $script);

        $watcher = $this->repo('config/core/services/tenant-network/run');
        $this->assertStringContainsString('--filter event=connect --filter event=disconnect', $watcher);
        $this->assertStringContainsString('timeout 60', $watcher);
        $this->assertTrue(is_executable(dirname(__DIR__, 5) . '/config/core/services/tenant-network/run'));

        $this->assertStringContainsString('ensure_packages nftables', $this->repo('scripts/installer.sh'));
        $this->assertStringContainsString('MISSING+=(nftables)', $this->repo('scripts/bootstrap-from-source.sh'));
        $this->assertStringContainsString('nft delete table bridge pa_tenants', $this->repo('scripts/uninstall.sh'));
    }

    /** ufw reloads leave the chains in place; the old CSF hooks are gone with CSF. */
    public function test_the_host_firewall_does_not_flush_the_account_rules(): void
    {
        $this->assertStringContainsString('MANAGE_BUILTINS=no', $this->repo('scripts/firewall/ufw.sh'));
        $this->assertStringNotContainsString('csfpost', $this->repo('scripts/tenant-network-firewall.sh'));
    }

    public function test_installers_and_core_start_apply_it(): void
    {
        $this->assertStringContainsString('tenant-network-firewall.sh --create', $this->repo('scripts/installer.sh'));
        $this->assertStringContainsString('tenant-network-firewall.sh --create', $this->repo('scripts/bootstrap-from-source.sh'));
        $this->assertStringContainsString('tenant-network-firewall.sh', $this->repo('dockerfiles/entrypoint-core.sh'));
        // Closed from boot, not only from when core starts.
        $this->assertStringContainsString('tenant-network-firewall.sh --install-units', $this->repo('scripts/installer.sh'));
        $this->assertStringContainsString('tenant-network-firewall.sh --install-units', $this->repo('scripts/bootstrap-from-source.sh'));
        $this->assertStringContainsString('panelalpha-tenant-guard.service', $this->repo('scripts/uninstall.sh'));
    }

    public function test_an_update_moves_existing_accounts_without_failing_on_one(): void
    {
        $installer = $this->repo('scripts/installer.sh');

        $this->assertStringContainsString('php artisan project:network:move --all || true', $installer);
        $this->assertLessThan(
            strpos($installer, 'project:network:move --all'),
            strpos($installer, 'php artisan migrate --force')
        );
        $this->assertStringContainsString('project:network:move --all', $this->repo('scripts/bootstrap-from-source.sh'));
    }

    public function test_accounts_are_created_on_the_tenant_network(): void
    {
        $template = $this->repo('templates/user/dind/project/docker-compose.yml.blade.php');

        $this->assertStringContainsString('name: ' . TenantNetwork::NAME, $template);
        $this->assertStringNotContainsString('name: ' . TenantNetwork::LEGACY_NAME, $template);
    }

    /** PHP-hosting accounts sat on core's network after DinD ones left it. */
    public function test_php_hosting_accounts_are_created_on_the_tenant_network(): void
    {
        $templates = glob(dirname(__DIR__, 5) . '/templates/user/default/project/docker-compose.yml*.blade.php') ?: [];
        $this->assertCount(4, $templates);
        foreach ($templates as $path) {
            $template = (string) file_get_contents($path);
            $this->assertStringContainsString('name: ' . TenantNetwork::NAME, $template, basename($path));
            $this->assertStringNotContainsString('name: ' . TenantNetwork::LEGACY_NAME, $template, basename($path));
        }
    }
}
