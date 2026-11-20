<?php

namespace Tests\Integration\System;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\Models\Setting;
use App\Models\Tunnel;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind\TenantEgressGuard;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

class EximRebuildTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-exim-' . bin2hex(random_bytes(4));
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->tmpRoot]));
        mkdir($this->tmpRoot . '/config/exim', 0777, true);
        mkdir($this->tmpRoot . '/templates/config', 0777, true);
        $this->writeTemplates();
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
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
        $this->assertStringContainsString('panelalpha/senders', $rewrite);
        $this->assertStringNotContainsString('${address:$header_from:}', $rewrite);
        $this->assertSame("server example.com\n", file_get_contents($this->tmpRoot . '/config/exim/panelalpha/senders'));

        $transport = file_get_contents($this->tmpRoot . '/config/exim/transport.conf');
        $this->assertStringNotContainsString('REMOTE_SMTP_SMARTHOST_PROTOCOL', $transport);

        $this->assertSame(['update-exim4.conf', 'exim4-restart'], $system->journal);
        $this->assertSame([
            [LocalRoot::CLIENT, 'compose.eximUpdateConfig', '--', $this->tmpRoot . '/docker-compose.yml'],
            [LocalRoot::CLIENT, 'compose.eximRestart', '--', $this->tmpRoot . '/docker-compose.yml'],
        ], $system->requests);
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
        $this->assertStringContainsString('panelalpha/senders', $rewrite);
    }

    public function test_rebuild_listens_on_this_hosts_docker0_and_the_tenant_gateway_and_relays_for_the_tenant_network(): void
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
        $this->assertStringContainsString(
            sprintf("dc_local_interfaces='%s ; %s ; %s'", TestNetwork::LOOPBACK, $gateway, $tenantGateway),
            $updateConf
        );
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

    /** The sending domain is the engine's line too: an install that still hides behind `panelalpha.engine` loses it. */
    public function test_rebuild_networks_rewrites_the_lines_the_engine_owns(): void
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

        $this->assertSame("dc_hide_mailname='true'\n"
            . "dc_local_interfaces='{$loopback} ; {$gateway} ; {$tenantGateway}'\n"
            . "dc_relay_nets='{$relayed} ; {$tenants}'\n"
            . "dc_readhost=''\n", file_get_contents($path));
        $this->assertSame(['update-exim4.conf', 'exim4-restart'], $system->journal);
    }

    /** With a sending domain configured it is what the mailname hides behind. */
    public function test_rebuild_networks_takes_the_readhost_from_the_sending_domain(): void
    {
        $path = $this->tmpRoot . '/config/exim/update-exim4.conf.conf';
        file_put_contents($path, "dc_readhost='panelalpha.engine'\n");
        $system = $this->recordingSystem();

        $this->eximWithConfig($system, ['sender_domain' => 'mail.host.example'])->rebuildNetworks();

        $this->assertSame(
            "dc_local_interfaces='" . TestNetwork::LOOPBACK . ' ; ' . Exim::FALLBACK_BRIDGE_GATEWAY . "'\n"
            . "dc_relay_nets='" . TestNetwork::LOOPBACK_CIDR . ' ; ' . TestNetwork::PRIVATE_172_CIDR . "'\n"
            . "dc_readhost='mail.host.example'\n",
            file_get_contents($path)
        );
    }

    /** The rule and the map are what a deploy of an older engine has to gain: it never rebuilds the mail settings. */
    public function test_rebuild_networks_also_writes_the_rule_and_the_sender_map(): void
    {
        file_put_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf', "dc_readhost=''\n");
        file_put_contents($this->tmpRoot . '/config/exim/rewrite.conf', "* \${address:\$header_from:} F\n");
        $system = $this->recordingSystem();

        $this->eximWithConfig($system, [])->rebuildNetworks();

        $this->assertSame(
            file_get_contents($this->tmpRoot . '/templates/config/exim/rewrite.conf'),
            file_get_contents($this->tmpRoot . '/config/exim/rewrite.conf')
        );
        $this->assertFileExists($this->tmpRoot . '/config/exim/panelalpha/senders');
    }

    /** The map alone: a changed domain or a restarted account never needs Exim restarted. */
    public function test_refreshing_the_senders_writes_the_map_and_applies_nothing(): void
    {
        $system = $this->recordingSystem();

        $this->eximWithConfig($system, ['sender_domain' => 'mail.example.net'])->refreshSenderMap();

        $this->assertSame([], $system->journal);
        $this->assertSame([], $system->requests);
        $this->assertSame("server mail.example.net\n", file_get_contents($this->tmpRoot . '/config/exim/panelalpha/senders'));
    }

    /**
     * The whole rule, as Exim reads it beside the map written with it: a sender at `<address>/<domain>` in the map keeps
     * its address, any other leaves at the map's `server` name, and at the server's name where the map has none.
     */
    public function test_the_written_rule_reads_the_map_written_with_it_and_falls_back_to_the_servers_name(): void
    {
        $alice = $this->makeUser('alice');
        $domain = $this->makeMainDomain($alice, 'shop-ab12.panelalpha.online');
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $alice->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop-ab12.panelalpha.online',
            'details' => [],
        ]);
        $system = $this->recordingSystem(['alice' => TestNetwork::container(17)]);

        $this->eximWithConfig($system, ['sender_domain' => 'mail.host.example'])->rebuildEximConfig();

        $map = '/etc/exim4/panelalpha/senders';
        $this->assertSame(
            '* "${if and{{exists{' . $map . '}}{eq{${lookup{$sender_host_address/${lc:$domain}}lsearch{' . $map . '}{allowed}{}}}{allowed}}}'
            . '{$local_part@$domain}'
            . '{$local_part@${if exists{' . $map . '}{${lookup{server}lsearch{' . $map . '}{$value}{mail.host.example}}}{mail.host.example}}}}" Ffrs',
            $this->ruleLine((string) file_get_contents($this->tmpRoot . '/config/exim/rewrite.conf'))
        );
        $this->assertSame(
            "server mail.host.example\n" . TestNetwork::container(17) . "/shop-ab12.panelalpha.online allowed\n",
            file_get_contents($this->tmpRoot . '/config/exim/panelalpha/senders'),
            'the keys the rule looks up: `server`, and the sending host\'s address with the lowercased domain'
        );
    }

    /** A server with no name of its own never sends as the mail container's hostname. */
    public function test_a_server_with_no_name_writes_the_rule_with_the_reserved_name(): void
    {
        Setting::set('cert_domain', '');
        Setting::set('default_ipv4', '');
        $system = $this->recordingSystem();

        $this->eximWithConfig($system, [])->rebuildEximConfig();

        $rule = $this->ruleLine((string) file_get_contents($this->tmpRoot . '/config/exim/rewrite.conf'));
        $this->assertStringContainsString('{$value}{' . Exim::UNNAMED_SENDER . '}}}{' . Exim::UNNAMED_SENDER . '}}}}" Ffrs', $rule);
        $this->assertStringNotContainsString('primary_hostname', $rule);
        $this->assertStringNotContainsString('palocal', $rule);
    }

    private function ruleLine(string $rewrite): string
    {
        $rules = array_values(array_filter(
            explode("\n", $rewrite),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
        ));
        $this->assertCount(1, $rules);

        return $rules[0];
    }

    /**
     * The files the installer copies are what Exim reads until a rebuild: they may not name a domain that does not
     * exist or trust the message's own From header, which any submitter sets.
     */
    public function test_the_shipped_exim_samples_keep_a_project_its_own_domain_and_rewrite_the_rest(): void
    {
        $dir = dirname(__DIR__, 4) . '/templates/config/exim';

        $updateConf = file_get_contents($dir . '/update-exim4.conf.conf');
        $this->assertNotFalse($updateConf);
        $this->assertStringContainsString("dc_readhost=''", $updateConf);
        $this->assertStringNotContainsString('panelalpha.engine', $updateConf);

        $rewrite = file_get_contents($dir . '/rewrite.conf');
        $this->assertNotFalse($rewrite);
        $this->assertStringContainsString('/etc/exim4/panelalpha/senders', $rewrite);
        $this->assertStringContainsString('$sender_host_address', $rewrite);
        $this->assertStringContainsString('$local_part@$domain', $rewrite);
        $this->assertStringContainsString(Exim::UNNAMED_SENDER, $rewrite);
        $this->assertStringNotContainsString('$primary_hostname', $rewrite, 'the mail container\'s own name is no one\'s domain');
        $this->assertStringNotContainsString('$header_from', $rewrite);
        $this->assertStringNotContainsString('panelalpha.engine', $rewrite);
    }

    /**
     * The account's own Docker daemon gives its docker0 {@see TestNetwork::DOCKER_BRIDGE_GATEWAY}, so docker0's gateway on a host with Docker's default
     * address is the account itself; the tenant network's gateway is an address the account reaches the host on.
     */
    public function test_a_project_is_given_the_tenant_gateway_exim_listens_on(): void
    {
        $system = $this->recordingSystem();
        $tenantGateway = TestNetwork::private(1);
        $exim = $this->eximWithConfig($system, [], [
            'bridge' => ['subnet' => TestNetwork::DOCKER_BRIDGE_CIDR, 'gateway' => Exim::FALLBACK_BRIDGE_GATEWAY],
            'pash-tenants' => ['subnet' => TestNetwork::prefix($tenantGateway, 16), 'gateway' => $tenantGateway],
        ]);

        $this->assertSame(
            [ComposeEnvironment::SMTP_HOST => $tenantGateway, ComposeEnvironment::SMTP_PORT => Exim::RELAY_PORT],
            $exim->relayEnvironment()
        );
        $this->assertContains(Exim::RELAY_PORT, explode(' ', TenantEgressGuard::HOST_PORTS));

        $exim->rebuildEximConfig();
        $updateConf = file_get_contents($this->tmpRoot . '/config/exim/update-exim4.conf.conf');
        $this->assertStringContainsString(
            sprintf("dc_local_interfaces='%s ; %s ; %s'", TestNetwork::LOOPBACK, Exim::FALLBACK_BRIDGE_GATEWAY, $tenantGateway),
            $updateConf,
            'the address a project is handed is one exim listens on'
        );
    }

    /** Nothing to inspect is no relay: a stock address is another install's, and docker0's may be the account's own. */
    public function test_no_relay_is_named_without_a_tenant_network(): void
    {
        $exim = $this->eximWithConfig($this->recordingSystem(), [], [
            'bridge' => ['subnet' => TestNetwork::prefix(TestNetwork::container(1), 16), 'gateway' => TestNetwork::container(1)],
        ]);

        $this->assertSame([], $exim->relayEnvironment());
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
        $this->assertSame([
            [LocalRoot::CLIENT, 'compose.eximSendTestEmail', '--', $this->tmpRoot . '/docker-compose.yml', 'probe@example.com'],
        ], $system->requests);
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
        mkdir($dir . '/exim', 0777, true);
        copy(
            dirname(__DIR__, 4) . '/templates/config/exim/rewrite.conf',
            $dir . '/exim/rewrite.conf'
        );
        file_put_contents(
            $dir . '/exim-transport.blade.php',
            "@if (!empty(\$smtp_implicit_tls))\n"
            . "REMOTE_SMTP_SMARTHOST_PROTOCOL = smtps\n"
            . "@endif\n"
        );
    }

    /** @param array<string, string> $accounts account container => its address on the tenant network */
    private function recordingSystem(array $accounts = []): System
    {
        return new class ($this->tmpRoot, $accounts) extends System {
            /** @var list<string> */
            public array $journal = [];

            /** @var list<string> */
            public array $execJournal = [];

            /** @var list<list<string>> */
            public array $requests = [];

            /** @param array<string, string> $accounts */
            public function __construct(
                private string $engineRoot,
                private array $accounts,
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
                $argv = (array) $cmd;
                $this->requests[] = $argv;
                $this->execJournal[] = implode(' ', $argv);
                if (in_array('compose.eximUpdateConfig', $argv, true)) {
                    $this->journal[] = 'update-exim4.conf';
                }
                if (in_array('compose.eximRestart', $argv, true)) {
                    $this->journal[] = 'exim4-restart';
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $this->requests[] = $argv;
                $address = $this->accounts[(string) end($argv)] ?? null;
                $output = $address === null ? 'ok-stdout' : (string) json_encode(['pash-tenants' => ['IPAddress' => $address]]);

                return new class ($output) extends Process {
                    public function __construct(private string $output)
                    {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return $this->output;
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

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
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
