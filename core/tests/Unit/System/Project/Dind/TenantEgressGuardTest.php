<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AccountTemplate;
use App\System\Project\Dind\Services\SupervisordServiceManager;
use App\System\Project\Dind\TenantEgressGuard;
use PHPUnit\Framework\TestCase;

/**
 * engine#217: from inside an account, customer code reached core (:2011,
 * :80), SFTP, FTP, phpMyAdmin and other accounts on pash-default-network, the
 * host on every port, and 169.254.169.254. The guard is run for real here,
 * with iptables and getent replaced by shims that record what it does.
 */
class TenantEgressGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egress-guard-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0777, true);

        // An account on 172.25.0.0/24, gateway .1 -- as read on a host.
        file_put_contents($this->dir . '/route', implode("\n", [
            "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT",
            "eth0\t00000000\t010019AC\t0003\t0\t0\t0\t00000000\t0\t0\t0",
            "eth0\t000019AC\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0",
            '',
        ]));
        // Chains that exist: the built-ins, plus whatever `-N` created.
        file_put_contents($this->dir . '/bin/iptables', <<<'SH'
#!/bin/sh
echo "$*" >> "$SHIM_DIR/calls"
case "$1 $2" in
  "-n -L") grep -qx "$3" "$SHIM_DIR/chains"; exit $? ;;
  "-N "*) echo "$2" >> "$SHIM_DIR/chains"; exit 0 ;;
esac
[ "$1" = "-C" ] && exit 1
exit 0
SH);
        file_put_contents($this->dir . '/bin/getent', <<<'SH'
#!/bin/sh
[ -f "$SHIM_DIR/nodns" ] && exit 2
case "$2" in
  database-users.shared-hosting.palocal|shared-hosting-sites-db-1) echo "172.25.0.3      STREAM $2" ;;
  panelalpha-cache-registry) echo "172.25.0.9      STREAM $2" ;;
  panelalpha-registry-proxy) echo "172.25.0.2      STREAM $2" ;;
  host.docker.internal) echo "172.20.0.1      STREAM $2" ;;
  *) exit 2 ;;
esac
SH);
        chmod($this->dir . '/bin/iptables', 0755);
        chmod($this->dir . '/bin/getent', 0755);
        file_put_contents($this->dir . '/chains', "OUTPUT\nFORWARD\n");
        file_put_contents($this->dir . '/guard.sh', TenantEgressGuard::script(['178.104.84.45', '172.25.0.1', 'not-an-ip']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return list<string> the iptables calls of one run */
    private function guardRun(): array
    {
        @unlink($this->dir . '/calls');
        $env = sprintf(
            'PATH=%s:$PATH SHIM_DIR=%s PA_ROUTE_FILE=%s PA_GUARD_STATE=%s',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/route'),
            escapeshellarg($this->dir . '/state')
        );
        exec("$env sh " . escapeshellarg($this->dir . '/guard.sh') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return is_file($this->dir . '/calls') ? file($this->dir . '/calls', FILE_IGNORE_NEW_LINES) : [];
    }

    private function rules(array $calls): array
    {
        return array_values(array_filter($calls, static fn (string $c): bool => str_starts_with($c, '-A PA-TENANT-EGRESS')));
    }

    public function test_only_mysql_the_registries_and_mail_or_sites_on_the_host_get_through(): void
    {
        $rules = $this->rules($this->guardRun());

        $this->assertSame([
            '-A PA-TENANT-EGRESS -o lo -j RETURN',
            '-A PA-TENANT-EGRESS -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.3 -p tcp --dport 3306 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.2 -p tcp --dport 5000 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.9 -p tcp --dport 5000 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.20.0.1 -p tcp --dport 25 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.20.0.1 -p tcp --dport 80 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.20.0.1 -p tcp --dport 443 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.20.0.1 -j DROP',
            '-A PA-TENANT-EGRESS -d 172.25.0.1 -p tcp --dport 25 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.1 -p tcp --dport 80 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.1 -p tcp --dport 443 -j RETURN',
            '-A PA-TENANT-EGRESS -d 172.25.0.1 -j DROP',
            '-A PA-TENANT-EGRESS -d 178.104.84.45 -p tcp --dport 25 -j RETURN',
            '-A PA-TENANT-EGRESS -d 178.104.84.45 -p tcp --dport 80 -j RETURN',
            '-A PA-TENANT-EGRESS -d 178.104.84.45 -p tcp --dport 443 -j RETURN',
            '-A PA-TENANT-EGRESS -d 178.104.84.45 -j DROP',
            // core, SFTP, FTP, phpMyAdmin and every other account
            '-A PA-TENANT-EGRESS -d 172.25.0.0/255.255.255.0 -j DROP',
            '-A PA-TENANT-EGRESS -d 169.254.0.0/16 -p udp --dport 53 -j RETURN',
            '-A PA-TENANT-EGRESS -d 169.254.0.0/16 -p tcp --dport 53 -j RETURN',
            '-A PA-TENANT-EGRESS -d 169.254.0.0/16 -j DROP',
        ], $rules);
    }

    public function test_it_hooks_the_account_and_later_the_inner_daemon(): void
    {
        $calls = $this->guardRun();
        $this->assertContains('-I OUTPUT -j PA-TENANT-EGRESS', $calls);
        $this->assertContains('-I FORWARD -j PA-TENANT-EGRESS', $calls);
        $this->assertNotContains('-I DOCKER-USER -j PA-TENANT-EGRESS', $calls, 'no inner daemon yet');

        // dockerd came up and made DOCKER-USER: the next pass hooks it,
        // without rebuilding a chain whose inputs did not change.
        file_put_contents($this->dir . '/chains', "DOCKER-USER\n", FILE_APPEND);
        $calls = $this->guardRun();
        $this->assertContains('-I DOCKER-USER -j PA-TENANT-EGRESS', $calls);
        $this->assertSame([], $this->rules($calls));
    }

    public function test_a_dns_failure_keeps_the_last_known_database_address(): void
    {
        $this->guardRun();
        file_put_contents($this->dir . '/nodns', '');
        // Something else changed, so the chain is rebuilt -- with MySQL still in it.
        file_put_contents($this->dir . '/guard.sh', TenantEgressGuard::script(['178.104.84.46']));

        $rules = $this->rules($this->guardRun());
        $this->assertContains('-A PA-TENANT-EGRESS -d 172.25.0.3 -p tcp --dport 3306 -j RETURN', $rules);
        $this->assertContains('-A PA-TENANT-EGRESS -d 172.25.0.9 -p tcp --dport 5000 -j RETURN', $rules);
    }

    public function test_it_never_fails_the_account_boot(): void
    {
        file_put_contents($this->dir . '/route', "garbage\n");
        $this->assertSame([], $this->rules($this->guardRun()));
    }

    public function test_the_account_template_renders_it_beside_the_init_script(): void
    {
        $model = new \App\Models\User();
        $model->username = 'acme';
        $model->details = ['UID' => 1234];
        $dind = $this->createStub(\App\System\Project\Dind::class);
        $dind->method('userModel')->willReturn($model);

        $template = (new \ReflectionClass(AccountTemplate::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AccountTemplate::class, 'project');
        $property->setValue($template, $dind);
        $scripts = $template->entrypointInitScripts();

        $this->assertArrayHasKey('useradd.sh', $scripts);
        $this->assertArrayHasKey(TenantEgressGuard::FILE, $scripts);
        $this->assertStringContainsString('CHAIN=PA-TENANT-EGRESS', $scripts[TenantEgressGuard::FILE]);
    }

    /** The loop that re-applies it hooks the inner daemon's chain, and runs the file the template renders. */
    public function test_the_keep_applied_loop_follows_the_script_and_the_chain(): void
    {
        $this->assertStringContainsString('[ -f /entrypoint.d/' . TenantEgressGuard::FILE . ' ] && sh /entrypoint.d/' . TenantEgressGuard::FILE, TenantEgressGuard::LOOP);
        $this->assertStringContainsString('iptables -C DOCKER-USER -j PA-TENANT-EGRESS', TenantEgressGuard::LOOP);
        $this->assertStringNotContainsString("'", TenantEgressGuard::LOOP, 'supervisord wraps it in single quotes');
        exec('sh -n -c ' . escapeshellarg(TenantEgressGuard::LOOP) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    /** s6 accounts: an executable service that ships down until the engine turns it on. */
    public function test_s6_accounts_run_the_loop_as_a_service(): void
    {
        $dir = __DIR__ . '/../../../../../../templates/user/dind/project/services/' . TenantEgressGuard::SERVICE;
        $run = (string) file_get_contents($dir . '/run');

        $this->assertStringStartsWith("#!/bin/sh\n", $run);
        $this->assertStringContainsString("\n" . TenantEgressGuard::LOOP . "\n", $run);
        $this->assertTrue(is_executable($dir . '/run'), 'run is executable');
        $this->assertFileExists($dir . '/down');
        $this->assertDirectoryDoesNotExist(dirname($dir, 2) . '/supervisord.conf.d');
    }

    /** Accounts still on supervisord get the same loop as a program. */
    public function test_supervisord_accounts_run_the_same_loop_as_a_program(): void
    {
        $on = SupervisordServiceManager::programConf(TenantEgressGuard::SERVICE, true);
        $off = SupervisordServiceManager::programConf(TenantEgressGuard::SERVICE, false);

        $this->assertStringContainsString("[program:egress-guard]\n", $on);
        $this->assertStringContainsString("command=sh -c '" . TenantEgressGuard::LOOP . "'\n", $on);
        $this->assertStringContainsString("autostart=true\n", $on);
        $this->assertStringContainsString("autostart=false\n", $off);
    }
}
