<?php

namespace Tests\Unit\System\Firewall;

use PHPUnit\Framework\TestCase;

/** The host side: scripts/firewall.sh and its ufw provider, and who runs them. */
class FirewallScriptTest extends TestCase
{
    private function repo(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 5) . '/' . $path);
    }

    /** scripts/firewall/ufw.test.sh runs ufw.sh against fake ufw, iptables, apt and systemctl. */
    public function test_the_ufw_provider_against_fakes(): void
    {
        $bash = trim((string) shell_exec('command -v bash'));
        if ($bash === '') {
            $this->markTestSkipped('bash is not installed');
        }

        exec(escapeshellarg($bash) . ' ' . escapeshellarg(dirname(__DIR__, 5) . '/scripts/firewall/ufw.test.sh') . ' 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertContains('All passed.', $out);
    }

    public function test_csf_is_gone(): void
    {
        $root = dirname(__DIR__, 5);
        foreach (['scripts/csf.sh', 'scripts/csf-publish-core.sh', 'third-party/csf.tgz'] as $gone) {
            $this->assertFileDoesNotExist($root . '/' . $gone);
        }
        $this->assertStringNotContainsString('/etc/csf', $this->repo('docker-compose.yml'));
        $this->assertStringNotContainsString('csf-web-ui', $this->repo('config/core/nginx.conf'));
    }

    public function test_the_installers_set_it_up_before_restarting_docker(): void
    {
        foreach (['scripts/installer.sh' => 'firewall.sh --install', 'scripts/bootstrap-from-source.sh' => 'firewall.sh --install'] as $file => $call) {
            $script = $this->repo($file);
            $this->assertStringContainsString($call, $script, $file);
            // CSF's uninstaller flushes Docker's chains; the restart puts them back.
            $this->assertLessThan(strpos($script, 'service docker restart', (int) strpos($script, $call)) ?: PHP_INT_MAX, (int) strpos($script, $call), $file);
        }
        $entrypoint = $this->repo('dockerfiles/entrypoint-core.sh');
        $this->assertStringContainsString('firewall_script=/opt/panelalpha/shared-hosting/scripts/firewall.sh', $entrypoint);
        $this->assertStringContainsString('"$firewall_script" --apply', $entrypoint);
        $this->assertStringContainsString('firewall.sh" --unhook', $this->repo('scripts/uninstall.sh'));
    }

    /** ufw judges a published port after Docker's DNAT, so host and container ports must agree. */
    public function test_every_published_port_is_the_same_inside(): void
    {
        $compose = $this->repo('docker-compose.yml');
        preg_match_all('/^\s+- "(?:([0-9.]+):)?([0-9-]+):([0-9-]+)(?:\/\w+)?"$/m', $compose, $ports, PREG_SET_ORDER);

        $public = array_filter($ports, fn (array $p): bool => !str_starts_with($p[1], '127.'));
        $this->assertCount(4, $public, '2011, 21, the FTP passive range and 2222');
        $this->assertSame(1, preg_match('/^managed_route_rules\(\) \{\n(.*?)^\}/ms', $this->repo('scripts/firewall/ufw.sh'), $routes));
        $ufw = $routes[1];
        foreach ($public as [$line, , $host, $container]) {
            $this->assertSame($host, $container, trim($line));
            // Each gets the engine's route rule, or the hook drops it.
            $this->assertStringContainsString('echo "' . str_replace('-', ':', $container) . ' tcp ', $ufw, trim($line));
        }
        // The template is what every install and update copies into config/sftp.
        $this->assertStringContainsString('sshd -D -e -p 2222', $this->repo('templates/config/sftp/entrypoint.sh'));
        $this->assertStringContainsString('sshd -D -e -p 2222', $this->repo('config/sftp/entrypoint.sh'));
    }

    public function test_core_and_the_host_read_the_same_provider_setting(): void
    {
        $this->assertStringContainsString('FIREWALL_PROVIDER=${FIREWALL_PROVIDER:-ufw}', $this->repo('docker-compose.yml'));
        $this->assertStringContainsString('s/^FIREWALL_PROVIDER=//p', $this->repo('scripts/firewall.sh'));
        $this->assertStringContainsString('firewall/$provider.sh', $this->repo('scripts/firewall.sh'));
    }
}
