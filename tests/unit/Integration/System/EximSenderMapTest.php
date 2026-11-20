<?php

namespace Tests\Integration\System;

use App\Models\Setting;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Services\Exim;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The rewrite rule decides from this map which sender a project may use: its own domains and aliases at the address
 * its account container sends from, and one name for every other sender.
 */
class EximSenderMapTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    /** @var array<string, string> container name => the networks Docker reports for it */
    private array $containers = [];

    /** @var array<string, list<string>> domain => the addresses its A and AAAA records name */
    private array $dns = [];

    /** @var list<string> the domains DNS was asked about */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-exim-map-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/config/exim', 0777, true);
        mkdir($this->tmpRoot . '/templates/config', 0777, true);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_the_map_names_the_server_and_every_domain_and_alias_of_a_running_project(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.example');
        $this->makeMainDomain($alice, 'alice.example');
        $this->addAlias($alice, 'shop.example', 'www.shop.example');
        $bob = $this->makeUser('bob');
        $this->makeMainDomain($bob, 'bob.example');
        $this->makeUser('carol');
        $this->container('alice', TestNetwork::container(17));
        $this->container('bob', TestNetwork::container(18));
        Setting::set('default_ipv4', TestNetwork::public(10));
        $this->pointHere('shop.example', 'alice.example', 'www.shop.example', 'bob.example');

        $map = $this->exim()->senderMap();

        $this->assertSame('server ' . str_replace('.', '-', TestNetwork::public(10)) . ".panelalpha.direct\n"
            . TestNetwork::container(17) . "/alice.example allowed\n"
            . TestNetwork::container(17) . "/shop.example allowed\n"
            . TestNetwork::container(17) . "/www.shop.example allowed\n"
            . TestNetwork::container(18) . "/bob.example allowed\n", $map);
    }

    /** An account that is down lends its address to no one: the next container Docker starts there must not inherit it. */
    public function test_a_project_whose_container_is_not_running_gets_no_line(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.example');
        Setting::set('default_ipv4', TestNetwork::public(10));

        $this->assertStringNotContainsString('shop.example', $this->exim()->senderMap());
        $this->assertSame('server ' . str_replace('.', '-', TestNetwork::public(10)) . ".panelalpha.direct\n", $this->exim()->senderMap());
    }

    /** A sender domain is not case sensitive, and the rewrite rule lowercases what it looks up. */
    public function test_a_domain_is_written_lowercase(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'Shop.Example');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));
        $this->pointHere('shop.example');

        $this->assertStringContainsString(TestNetwork::container(17) . "/shop.example allowed\n", $this->exim()->senderMap());
    }

    public function test_the_server_name_is_the_configured_sending_domain_the_certificate_name_then_the_address_zone(): void
    {
        $this->makeUser('alice');
        Setting::set('default_ipv4', TestNetwork::public(10));

        $this->assertSame('mail.host.example', $this->exim(['sender_domain' => 'Mail.Host.Example'])->senderName());

        Setting::set('cert_domain', 'panel.host.example');
        $this->assertSame('panel.host.example', $this->exim()->senderName());

        Setting::set('cert_domain', '');
        $this->assertSame(str_replace('.', '-', TestNetwork::public(10)) . '.panelalpha.direct', $this->exim()->senderName());
    }

    /** Nothing to name the server with is no name at all: the rule then falls back to the reserved name. */
    public function test_no_sending_domain_no_certificate_and_no_address_names_nothing(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'shop.example');
        Setting::set('cert_domain', '');
        Setting::set('default_ipv4', '');

        $this->assertSame('', $this->exim()->senderName());
        $this->assertSame('', $this->exim()->senderMap());
    }

    public function test_refresh_writes_the_map_and_creates_its_directory(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.example');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));
        $this->pointHere('shop.example');
        $exim = $this->exim(['sender_domain' => 'mail.host.example']);

        $exim->refreshSenderMap();

        $path = $this->tmpRoot . '/' . Exim::SENDER_MAP_DIR . '/' . Exim::SENDER_MAP_FILE;
        $this->assertSame("server mail.host.example\n"
            . TestNetwork::container(17) . "/shop.example allowed\n", file_get_contents($path));
    }

    /** Attaching a domain checks its format only: one that is not this server's must not keep its sender. */
    public function test_an_attached_domain_that_does_not_resolve_here_gets_no_line(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.example');
        $this->makeMainDomain($alice, 'bank-example.com');
        $this->addAlias($alice, 'shop.example', 'www.bank-example.com');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));
        $this->pointHere('shop.example');
        $this->dns['bank-example.com'] = [TestNetwork::public2(80)];

        $map = $this->exim()->senderMap();

        $this->assertStringContainsString(TestNetwork::container(17) . "/shop.example allowed\n", $map);
        $this->assertStringNotContainsString('bank-example.com', $map);
    }

    /** A name under the operator's own base domain is the operator's, wherever its DNS points. */
    public function test_a_domain_below_the_sites_base_domain_is_listed_without_asking_dns(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'alice.sites.host.example');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));
        Setting::set('default_wildcard_domain', 'sites.host.example');

        $map = $this->exim()->senderMap();

        $this->assertStringContainsString(TestNetwork::container(17) . "/alice.sites.host.example allowed\n", $map);
        $this->assertSame([], $this->asked);
    }

    public function test_a_panelalpha_online_domain_is_listed_only_while_a_tunnel_of_the_project_holds_it(): void
    {
        $alice = $this->makeUser('alice');
        $domain = $this->makeMainDomain($alice, 'shop-ab12.panelalpha.online');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));

        $this->assertStringNotContainsString('panelalpha.online', $this->exim()->senderMap());

        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $alice->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop-ab12.panelalpha.online',
            'details' => [],
        ]);

        $this->assertStringContainsString(
            TestNetwork::container(17) . "/shop-ab12.panelalpha.online allowed\n",
            $this->exim()->senderMap()
        );
    }

    /** The map is rewritten every minute; a domain's DNS is asked once in {@see Exim::SENDER_DNS_TTL_SECONDS}. */
    public function test_a_domains_dns_answer_is_reused_between_refreshes(): void
    {
        $alice = $this->makeUser('alice');
        $this->makeMainDomain($alice, 'shop.example');
        $this->container('alice', TestNetwork::container(17));
        Setting::set('default_ipv4', TestNetwork::public(10));
        $this->pointHere('shop.example');

        $this->exim()->senderMap();
        $this->exim()->senderMap();

        $this->assertSame(['shop.example'], $this->asked);
    }

    /** The rewrite rule reads `<address>/<domain>`: the address is the one the account container sends from. */
    public function test_the_address_is_the_accounts_own_ip_on_the_tenant_network(): void
    {
        $address = TestNetwork::container(17);

        $this->assertSame($address, Exim::senderAddress(json_encode([
            'bridge' => ['IPAddress' => TestNetwork::container(2)],
            'pash-tenants' => ['IPAddress' => $address, 'IPPrefixLen' => 16],
        ])));
        $this->assertSame($address, Exim::senderAddress(json_encode([
            'pash-default-network' => ['IPAddress' => $address],
        ])));
        $this->assertSame($address, Exim::senderAddress(json_encode([
            'something-else' => ['IPAddress' => $address],
        ])));
        $this->assertSame(TestNetwork::container(2), Exim::senderAddress(json_encode([
            'bridge' => ['IPAddress' => TestNetwork::container(2)],
            'pash-tenants' => ['IPAddress' => '', 'IPPrefixLen' => 16],
        ])));
        $this->assertNull(Exim::senderAddress(json_encode([
            'host' => ['IPAddress' => ''],
            'none' => ['IPAddress' => '2001:db8::5'],
        ])));
        $this->assertNull(Exim::senderAddress('not json'));
    }

    private function pointHere(string ...$domains): void
    {
        foreach ($domains as $domain) {
            $this->dns[$domain] = [TestNetwork::public(10)];
        }
    }

    private function addAlias(User $user, string $domain, string $alias): void
    {
        $model = $user->domains()->where('domain', $domain)->firstOrFail();
        $model->addAlias($alias);
        $model->save();
    }

    private function container(string $account, string $address): void
    {
        $this->containers[$account] = json_encode([
            'bridge' => ['IPAddress' => TestNetwork::container(2)],
            'pash-tenants' => ['IPAddress' => $address, 'IPPrefixLen' => 16],
        ]);
    }

    /** @param array<string, string> $config */
    private function exim(array $config = []): Exim
    {
        $resolve = function (string $domain): array {
            $this->asked[] = $domain;

            return $this->dns[$domain] ?? [];
        };

        return new class ($this->system(), $config, $resolve) extends Exim {
            /** @param array<string, string> $config */
            public function __construct(System $system, private array $config, private \Closure $resolve)
            {
                parent::__construct($system);
            }

            protected function networkAddresses(string $network): array
            {
                return [];
            }

            protected function resolveAddresses(string $domain): array
            {
                return ($this->resolve)($domain);
            }

            protected function eximConfig(): array
            {
                return array_merge([
                    'smarthost_provider' => '',
                    'sender_domain' => '',
                    'smtp_implicit_tls' => '',
                ], $this->config);
            }
        };
    }

    private function system(): System
    {
        return new class ($this->tmpRoot, $this->containers) extends System {
            /** @param array<string, string> $containers */
            public function __construct(private string $engineRoot, private array $containers)
            {
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
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $account = (string) end($argv);
                $networks = $this->containers[$account] ?? null;

                return new class ($networks) extends Process {
                    public function __construct(private ?string $networks)
                    {
                        parent::__construct(['true']);
                    }

                    public function getOutput(): string
                    {
                        return $this->networks ?? '';
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->networks === null ? 1 : 0;
                    }
                };
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private System $recording)
                    {
                        parent::__construct($recording);
                    }

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
                        if (! is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }

                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null
                    ): void {
                        $dir = dirname($path);
                        if (! is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
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
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
