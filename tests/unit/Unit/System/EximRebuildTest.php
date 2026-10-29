<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class EximRebuildTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-exim-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/config/exim', 0777, true);
        mkdir($this->tmpRoot . '/templates/config', 0777, true);
        $this->writeTemplates();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_exim_returns_exim_collaborator(): void
    {
        $system = $this->recordingSystem();
        $this->assertInstanceOf(Exim::class, $system->exim());
    }

    public function test_rebuild_sendgrid_writes_files_and_reloads_mail(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, [
            'smarthost_provider' => 'sendgrid',
            'sendgrid_api_token' => 'sg-token-123',
            'sender_domain' => 'example.com',
            'smtp_implicit_tls' => '',
        ]);

        $exim->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString("dc_eximconfig_configtype='smarthost'", $updateConf);
        $this->assertStringContainsString("dc_smarthost='smtp.sendgrid.net::587'", $updateConf);
        $this->assertStringContainsString("dc_readhost='example.com'", $updateConf);

        $passwd = file_get_contents($this->tmpRoot . '/config/exim/passwd.client');
        $this->assertSame("*:apikey:sg-token-123\n", $passwd);

        $rewrite = file_get_contents($this->tmpRoot . '/config/exim/rewrite.conf');
        $this->assertStringContainsString('example.com', $rewrite);
        $this->assertStringNotContainsString('${address:$header_from:}', $rewrite);

        $transport = file_get_contents($this->tmpRoot . '/config/exim/transport.conf');
        $this->assertStringNotContainsString('REMOTE_SMTP_SMARTHOST_PROTOCOL', $transport);

        $this->assertContains('update-exim4.conf', $system->journal);
        $this->assertContains('exim4-restart', $system->journal);

        $updateCmd = null;
        $reloadCmd = null;
        foreach ($system->execJournal as $cmd) {
            if (str_contains($cmd, 'update-exim4.conf')) {
                $updateCmd = $cmd;
            }
            if (str_contains($cmd, 'service exim4 start')) {
                $reloadCmd = $cmd;
            }
        }
        $this->assertNotNull($updateCmd);
        $this->assertStringContainsString('docker compose -f', $updateCmd);
        $this->assertStringContainsString('exec mail update-exim4.conf', $updateCmd);
        $this->assertStringNotContainsString('exec -T mail', $updateCmd);

        $this->assertNotNull($reloadCmd);
        $this->assertStringContainsString('exec mail sh -c', $reloadCmd);
        $this->assertStringNotContainsString('exec -T mail', $reloadCmd);
    }

    /**
     * passwd.client is plain text, not HTML: `{{ }}` used to escape the secret,
     * so Exim sent `p&amp;ss&#039;1` and the smarthost rejected the login while
     * the settings API reported success (engine#790).
     */
    public function test_a_password_with_html_specials_is_written_byte_for_byte(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, [
            'smarthost_provider' => 'smtp',
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => '587',
            'smtp_username' => 'user&co',
            'smtp_password' => "p&ss'1<>\"x",
            'sender_domain' => 'mail.example.net',
        ]);

        $exim->rebuildEximConfig();

        $passwd = file_get_contents($this->tmpRoot . '/config/exim/passwd.client');
        $this->assertSame("*:user&co:p&ss'1<>\"x\n", $passwd);
        $this->assertStringNotContainsString('&amp;', $passwd);
        $this->assertStringNotContainsString('&#039;', $passwd);
        $this->assertStringNotContainsString('&lt;', $passwd);
        $this->assertStringNotContainsString('&gt;', $passwd);
        $this->assertStringNotContainsString('&quot;', $passwd);
    }

    /** Docker leaves a directory where a bind-mounted file was missing; writing into it is not a rebuild. */
    public function test_a_config_path_that_is_a_directory_fails_the_rebuild(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, ['sender_domain' => 'example.com']);
        $path = $this->tmpRoot . '/config/exim/passwd.client';
        mkdir($path);

        $failure = null;
        try {
            $exim->rebuildEximConfig();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }

        $this->assertSame("Cannot write the Exim config {$path}: it is a directory, not a file.", $failure);
        $this->assertSame(['.', '..'], scandir($path), 'nothing was written into the directory');
        $this->assertNotContains('update-exim4.conf', $system->journal, 'the half-written config was not applied');
    }

    public function test_rebuild_smtp_with_implicit_tls_sets_smarthost_and_transport(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, [
            'smarthost_provider' => 'smtp',
            'smtp_host' => 'smtp.example.net',
            'smtp_port' => '465',
            'smtp_username' => 'user',
            'smtp_password' => 'secret',
            'smtp_implicit_tls' => '1',
            'sender_domain' => 'mail.example.net',
        ]);

        $exim->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString("dc_eximconfig_configtype='smarthost'", $updateConf);
        $this->assertStringContainsString("dc_smarthost='smtp.example.net::465'", $updateConf);

        $passwd = file_get_contents($this->tmpRoot . '/config/exim/passwd.client');
        $this->assertSame("*:user:secret\n", $passwd);

        $transport = file_get_contents($this->tmpRoot . '/config/exim/transport.conf');
        $this->assertStringContainsString('REMOTE_SMTP_SMARTHOST_PROTOCOL = smtps', $transport);
    }

    /** Exim splits dc_smarthost on single colons: measured, a plain IPv6 address was routed to host `2001`. */
    public function test_an_ipv6_smarthost_is_bracketed_with_its_colons_doubled(): void
    {
        $this->assertSame('[2001::db8::::25]::587', Exim::smarthost('2001:db8::25', '587'));
        $this->assertSame('[::::1]::2525', Exim::smarthost('::1', '2525'));
        $this->assertSame(TestNetwork::public3(10) . '::587', Exim::smarthost(TestNetwork::public3(10), '587'));
        $this->assertSame('smtp.example.net::465', Exim::smarthost('smtp.example.net', '465'));

        $system = $this->recordingSystem();
        $this->eximWithConfig($system, [
            'smarthost_provider' => 'amazon_ses',
            'amazon_ses_smtp_endpoint' => '2001:db8::25',
            'amazon_ses_starttls_port' => '587',
        ])->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString("dc_smarthost='[2001::db8::::25]::587'", $updateConf);
    }

    public function test_rebuild_empty_provider_uses_internet_type(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, [
            'smarthost_provider' => '',
            'sender_domain' => '',
            'smtp_implicit_tls' => '',
        ]);

        $exim->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString("dc_eximconfig_configtype='internet'", $updateConf);
        $this->assertStringContainsString("dc_smarthost=''", $updateConf);

        $passwd = file_get_contents($this->tmpRoot . '/config/exim/passwd.client');
        $this->assertSame("*::\n", $passwd);

        $rewrite = file_get_contents($this->tmpRoot . '/config/exim/rewrite.conf');
        $this->assertStringContainsString('${address:$header_from:}', $rewrite);
    }

    public function test_rebuild_listens_on_this_hosts_docker0_and_relays_for_the_tenant_network(): void
    {
        $system = $this->recordingSystem();
        $gateway = TestNetwork::container(1);
        $tenantGateway = TestNetwork::private(1);
        $tenants = TestNetwork::prefix($tenantGateway, 16);
        $exim = $this->eximWithConfig($system, [], [
            'bridge' => ['subnet' => TestNetwork::prefix($gateway, 16), 'gateway' => $gateway],
            'pash-tenants' => ['subnet' => $tenants, 'gateway' => $tenantGateway],
        ]);

        $exim->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString(sprintf("dc_local_interfaces='%s ; %s'", TestNetwork::LOOPBACK, $gateway), $updateConf);
        $this->assertStringNotContainsString(Exim::FALLBACK_BRIDGE_GATEWAY, $updateConf);
        $this->assertStringContainsString(
            sprintf("dc_relay_nets='%s ; %s ; %s'", TestNetwork::LOOPBACK_CIDR, TestNetwork::PRIVATE_172_CIDR, $tenants),
            $updateConf
        );
    }

    public function test_rebuild_falls_back_to_dockers_default_when_nothing_can_be_inspected(): void
    {
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, []);

        $exim->rebuildEximConfig();

        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString(
            sprintf("dc_local_interfaces='%s ; %s'", TestNetwork::LOOPBACK, Exim::FALLBACK_BRIDGE_GATEWAY),
            $updateConf
        );
        $this->assertStringContainsString(
            sprintf("dc_relay_nets='%s ; %s'", TestNetwork::LOOPBACK_CIDR, TestNetwork::PRIVATE_172_CIDR),
            $updateConf
        );
    }

    public function test_rebuild_networks_rewrites_only_the_listen_and_relay_lines(): void
    {
        $path = $this->tmpRoot . '/config/exim/update-exim4.conf.conf';
        $loopback = TestNetwork::LOOPBACK;
        $fallback = Exim::FALLBACK_BRIDGE_GATEWAY;
        $relayed = TestNetwork::LOOPBACK_CIDR . ' ; ' . TestNetwork::PRIVATE_172_CIDR;
        $gateway = TestNetwork::container(1);
        $tenantGateway = TestNetwork::add(TestNetwork::private(1), 65536);
        $tenants = TestNetwork::prefix($tenantGateway, 16);
        file_put_contents($path, "dc_readhost='panelalpha.engine'\n"
            . "dc_relay_nets=''\n"
            . "dc_hide_mailname='true'\n"
            . "dc_local_interfaces='{$loopback} ; {$fallback}'\n"
            . "dc_relay_nets='{$relayed}'\n");
        $system = $this->recordingSystem();
        $exim = $this->eximWithConfig($system, [], [
            'bridge' => ['subnet' => TestNetwork::prefix($gateway, 16), 'gateway' => $gateway],
            'pash-tenants' => ['subnet' => $tenants, 'gateway' => $tenantGateway],
        ]);

        $exim->rebuildNetworks();

        $this->assertSame("dc_readhost='panelalpha.engine'\n"
            . "dc_hide_mailname='true'\n"
            . "dc_local_interfaces='{$loopback} ; {$gateway}'\n"
            . "dc_relay_nets='{$relayed} ; {$tenants}'\n", file_get_contents($path));
        $this->assertSame(['update-exim4.conf', 'exim4-restart'], $system->journal);
    }

    /**
     * The daemon a container start left retrying its bind has no pid file, so
     * `service exim4 stop` misses it; the restart also stops every `exim4 -bd`
     * before starting the new one.
     */
    public function test_the_restart_stops_a_daemon_the_init_script_missed(): void
    {
        $script = Exim::RESTART_SCRIPT;

        $stop = strpos($script, 'service exim4 stop');
        $kill = strpos($script, 'kill $left');
        $start = strpos($script, 'service exim4 start');
        $this->assertNotFalse($stop);
        $this->assertNotFalse($kill);
        $this->assertNotFalse($start);
        $this->assertTrue($stop < $kill && $kill < $start);
        $this->assertStringContainsString('-bd', $script);
        $this->assertStringContainsString('kill -9', $script);
    }

    public function test_parse_network_addresses(): void
    {
        $gateway = TestNetwork::container(1);
        $subnet = TestNetwork::prefix($gateway, 16);
        $tenantGateway = TestNetwork::private(1);
        $tenants = TestNetwork::prefix($tenantGateway, 16);

        $this->assertSame(
            ['subnet' => $subnet, 'gateway' => $gateway],
            Exim::parseNetworkAddresses("{$subnet} {$gateway}\n")
        );
        $this->assertSame(
            ['subnet' => $tenants, 'gateway' => $tenantGateway],
            Exim::parseNetworkAddresses("fd00::/64 fd00::1\n{$tenants} {$tenantGateway}\n")
        );
        $this->assertSame(['subnet' => TestNetwork::DOCKER_BRIDGE_CIDR], Exim::parseNetworkAddresses(TestNetwork::DOCKER_BRIDGE_CIDR . ' '));
        $this->assertSame([], Exim::parseNetworkAddresses(''));
        $this->assertSame([], Exim::parseNetworkAddresses('ok-stdout'));
    }

    public function test_send_test_email_uses_run_process_and_returns_process_result(): void
    {
        $system = $this->recordingSystem();
        $exim = $system->exim();

        $result = $exim->sendTestEmail('probe@example.com');

        $this->assertSame('ok-stdout', $result['stdout']);
        $this->assertSame('ok-stderr', $result['stderr']);
        $this->assertSame(0, $result['exit_code']);

        $this->assertEmpty($system->execJournal);
        $this->assertCount(1, $system->processJournal);

        $cmd = $system->processJournal[0];
        $this->assertStringContainsString('docker compose -f', $cmd);
        $this->assertStringContainsString('exec mail bash -c', $cmd);
        $this->assertStringContainsString('exim4 -v -odf', $cmd);
        $this->assertStringContainsString('probe@example.com', $cmd);
        $this->assertStringNotContainsString('exec -T mail', $cmd);
    }

    /**
     * @param array<string, string> $config
     */
    private function eximWithConfig(System $system, array $config, array $networks = []): Exim
    {
        return new class ($system, $config, $networks) extends Exim {
            /**
             * @param array<string, string> $config
             * @param array<string, array{subnet?: string, gateway?: string}> $networks
             */
            public function __construct(
                System $system,
                private array $config,
                private array $networks,
            ) {
                parent::__construct($system);
            }

            protected function networkAddresses(string $network): array
            {
                return $this->networks[$network] ?? [];
            }

            protected function eximConfig(): array
            {
                return array_merge([
                    'smarthost_provider' => '',
                    'sendgrid_api_token' => '',
                    'mailchannels_username' => '',
                    'mailchannels_password' => '',
                    'amazon_ses_smtp_endpoint' => '',
                    'amazon_ses_starttls_port' => '',
                    'amazon_ses_smtp_username' => '',
                    'amazon_ses_smtp_password' => '',
                    'smtp_host' => '',
                    'smtp_port' => '',
                    'smtp_username' => '',
                    'smtp_password' => '',
                    'smtp_implicit_tls' => '',
                    'sender_domain' => '',
                ], $this->config);
            }
        };
    }

    private function writeTemplates(): void
    {
        $dir = $this->tmpRoot . '/templates/config';
        file_put_contents(
            $dir . '/exim-update-conf.blade.php',
            "dc_readhost='{{ \$dc_readhost }}'\n"
            . "dc_eximconfig_configtype='{{ \$dc_eximconfig_configtype }}'\n"
            . "dc_smarthost='{{ \$dc_smarthost }}'\n"
            . "dc_local_interfaces='{{ \$dc_local_interfaces }}'\n"
            . "dc_relay_nets='{{ \$dc_relay_nets }}'\n"
        );
        file_put_contents(
            $dir . '/exim-passwd.blade.php',
            "*:{!! \$username !!}:{!! \$password !!}\n"
        );
        file_put_contents(
            $dir . '/exim-rewrite.blade.php',
            "@if (!empty(\$sender_domain))\n"
            . "*  \${local_part}_at_\${domain}{{ '@' }}{{ \$sender_domain }} Ffrs\n"
            . "@else\n"
            . "* \${address:\$header_from:} F\n"
            . "@endif\n"
        );
        file_put_contents(
            $dir . '/exim-transport.blade.php',
            "@if (!empty(\$smtp_implicit_tls))\n"
            . "REMOTE_SMTP_SMARTHOST_PROTOCOL = smtps\n"
            . "@endif\n"
        );
    }

    private function recordingSystem(): System
    {
        return new class ($this->tmpRoot) extends System {
            /** @var list<string> */
            public array $journal = [];

            /** @var list<string> */
            public array $execJournal = [];

            /** @var list<string> */
            public array $processJournal = [];

            public function __construct(
                private string $engineRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function composeFilePath(): string
            {
                return $this->engineRoot . '/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->execJournal[] = $line;
                if (str_contains($line, 'update-exim4.conf')) {
                    $this->journal[] = 'update-exim4.conf';
                }
                if (str_contains($line, 'service exim4 stop') && str_contains($line, 'service exim4 start')) {
                    $this->journal[] = 'exim4-restart';
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->processJournal[] = $line;

                return new class extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return 'ok-stdout';
                    }

                    public function getErrorOutput(): string
                    {
                        return 'ok-stderr';
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }

            public function filesystem(): Filesystem
            {
                $system = $this;

                return new class ($system) extends Filesystem {
                    public function fileGetContents(string $path): string
                    {
                        $contents = file_get_contents($path);
                        if ($contents === false) {
                            throw new \RuntimeException("Cannot read {$path}");
                        }

                        return $contents;
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    /** Writes as `cp` does: into a directory found at the path. */
                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null
                    ): void {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents(is_dir($path) ? $path . '/tmp_copy' : $path, $contents);
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
