<?php

namespace Tests\Integration\Console\Domains;

use App\Console\Commands\Domains\DomainsCreate;
use App\Console\Commands\Domains\DomainsDelete;
use App\Console\Commands\Domains\DomainsSetProxy;
use App\Console\Commands\Domains\DomainsUnsetProxy;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Domain as ProjectDomain;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordedDomainHost;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/** `domain:create`, `domain:delete`, `domain:set-proxy` and `domain:unset-proxy` once confirmed: the host changes each makes, in order, and what a failure leaves. */
class DomainsHostChangesCommandTest extends TestCase
{
    use InMemoryDatabase;

    private RecordedDomainHost $host;

    private ?\Throwable $tunnelFailure = null;

    /** @var list<string> */
    private array $tunnelSyncs = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $alice = $this->makeUser('alice', ['template' => 'dind']);
        $this->makeMainDomain($alice, 'alice.example.test');
        $this->useHost(new RecordedDomainHost());
    }

    private function useHost(RecordedDomainHost $host): void
    {
        $this->host = $host;
        $this->app->instance(System::class, $host->system());
    }

    /** @param class-string<Command> $class */
    private function command(string $class, array $input): int
    {
        $test = $this;
        $command = match ($class) {
            DomainsCreate::class => new class($test) extends DomainsCreate
            {
                public function __construct(private readonly DomainsHostChangesCommandTest $test)
                {
                    parent::__construct();
                }

                protected function projectDomain(Domain $domain): ProjectDomain
                {
                    return $this->test->host()->domain($domain);
                }

                protected function project(User $user): Project
                {
                    return $this->test->host()->project($user);
                }
            },
            DomainsDelete::class => new class($test) extends DomainsDelete
            {
                public function __construct(private readonly DomainsHostChangesCommandTest $test)
                {
                    parent::__construct();
                }

                protected function projectDomain(Domain $domain): ProjectDomain
                {
                    return $this->test->host()->domain($domain);
                }

                protected function project(User $user): Project
                {
                    return $this->test->host()->project($user);
                }
            },
            DomainsSetProxy::class => new class($test) extends DomainsSetProxy
            {
                public function __construct(private readonly DomainsHostChangesCommandTest $test)
                {
                    parent::__construct();
                }

                protected function projectDomain(Domain $domain): ProjectDomain
                {
                    return $this->test->host()->domain($domain);
                }

                protected function syncTunnelIngress(User $user, Domain $domain): void
                {
                    $this->test->tunnelSynced($user, $domain);
                }
            },
            DomainsUnsetProxy::class => new class($test) extends DomainsUnsetProxy
            {
                public function __construct(private readonly DomainsHostChangesCommandTest $test)
                {
                    parent::__construct();
                }

                protected function projectDomain(Domain $domain): ProjectDomain
                {
                    return $this->test->host()->domain($domain);
                }

                protected function syncTunnelIngress(User $user, Domain $domain): void
                {
                    $this->test->tunnelSynced($user, $domain);
                }
            },
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input + ['--force' => true]), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function host(): RecordedDomainHost
    {
        return $this->host;
    }

    public function tunnelSynced(User $user, Domain $domain): void
    {
        $this->tunnelSyncs[] = "{$user->username} {$domain->domain}";
        if ($this->tunnelFailure !== null) {
            throw $this->tunnelFailure;
        }
    }

    private function tunnel(): void
    {
        $domain = Domain::findByName('alice.example.test');
        Tunnel::create(['domain_id' => $domain->id, 'user_id' => $domain->user_id, 'provider' => Tunnel::PROVIDER_CLOUDFLARE, 'hostname' => 'app.example.com', 'details' => []]);
    }

    /** @return array<int, string> listen port => upstream */
    private function rules(): array
    {
        return ProxyRule::query()->where('server_name', 'alice.example.test')->orderBy('listen_port')->get()
            ->mapWithKeys(fn (ProxyRule $r): array => [$r->listen_port => "{$r->upstream_host}:{$r->upstream_port}"])
            ->all();
    }

    public function test_create_sets_the_domain_up_and_then_the_projects_handlers_and_services(): void
    {
        $this->assertSame(0, $this->command(DomainsCreate::class, ['domain' => 'shop.example.test', '--project' => 'alice']));

        $this->assertSame(['create shop.example.test', 'php handlers alice', 'services alice'], $this->host->recorded());
        $this->assertStringContainsString("Domain 'shop.example.test' created.", $this->printed);
        $this->assertStringNotContainsString('Proxy rules set', $this->printed);
        $this->assertSame('addon', Domain::findByName('shop.example.test')?->type);
    }

    public function test_create_with_an_upstream_writes_both_rules_rebuilds_and_reloads(): void
    {
        $this->assertSame(0, $this->command(DomainsCreate::class, ['domain' => 'shop.example.test', '--project' => 'alice', '--proxy-to' => '3000']));

        $this->assertSame([
            'create shop.example.test', 'php handlers alice', 'services alice', 'rebuild shop.example.test', 'webserver reload',
        ], $this->host->recorded());
        $this->assertStringContainsString('Proxy rules set → alice:3000 (listen 80/443).', $this->printed);
        $rules = ProxyRule::query()->where('server_name', 'shop.example.test')->orderBy('listen_port')->get();
        $this->assertSame([80, 443], $rules->pluck('listen_port')->all());
        $this->assertSame(['alice', 'alice'], $rules->pluck('upstream_host')->all());
        $this->assertSame('domain-create', $rules[0]->metadata['source'] ?? null);
        $this->assertSame('443→3000 for shop.example.test', $rules[1]->metadata['description'] ?? null);
    }

    /** The host side of the rollback runs on the engine's own System, so a stand-in root broker answers it. */
    public function test_a_failed_create_says_why_and_takes_the_row_back(): void
    {
        $this->useHost(new RecordedDomainHost('services'));
        $broker = new ScriptedBroker();

        try {
            $this->assertSame(1, $this->command(DomainsCreate::class, ['domain' => 'shop.example.test', '--project' => 'alice']));
        } finally {
            $broker->uninstall();
        }

        $this->assertSame(['create shop.example.test', 'php handlers alice', 'services alice'], $this->host->recorded());
        $this->assertStringContainsString('Domain create failed: services alice failed', $this->printed);
        $this->assertNull(Domain::findByName('shop.example.test'));
    }

    public function test_a_proxy_rebuild_that_fails_rolls_the_create_back_too(): void
    {
        $this->useHost(new RecordedDomainHost('rebuild'));
        $broker = new ScriptedBroker();

        try {
            $this->assertSame(1, $this->command(DomainsCreate::class, ['domain' => 'shop.example.test', '--project' => 'alice', '--proxy-to' => '3000']));
        } finally {
            $broker->uninstall();
        }

        $this->assertStringContainsString('Domain create failed: rebuild shop.example.test failed', $this->printed);
        $this->assertNull(Domain::findByName('shop.example.test'));
    }

    public function test_delete_removes_the_vhost_and_the_row_and_resyncs_the_project(): void
    {
        Domain::query()->create([
            'user_id' => User::findByUsername('alice')->id, 'domain' => 'shop.example.test', 'type' => 'addon',
            'details' => ['document_root' => '/shop.example.test/public_html'],
        ]);

        $this->assertSame(0, $this->command(DomainsDelete::class, ['domain' => 'shop.example.test', '--project' => 'alice']));

        $this->assertSame(['delete shop.example.test', 'php handlers alice', 'services alice'], $this->host->recorded());
        $this->assertStringContainsString("Domain 'shop.example.test' deleted. Its files are kept in", $this->printed);
        $this->assertNull(Domain::findByName('shop.example.test'));
    }

    public function test_a_failed_delete_says_why_and_keeps_the_row(): void
    {
        $this->useHost(new RecordedDomainHost('delete'));
        Domain::query()->create([
            'user_id' => User::findByUsername('alice')->id, 'domain' => 'shop.example.test', 'type' => 'addon',
            'details' => ['document_root' => '/shop.example.test/public_html'],
        ]);

        $this->assertSame(1, $this->command(DomainsDelete::class, ['domain' => 'shop.example.test', '--project' => 'alice']));

        $this->assertStringContainsString('Domain delete failed: delete shop.example.test failed', $this->printed);
        $this->assertNotNull(Domain::findByName('shop.example.test'));
    }

    public function test_set_proxy_writes_the_rules_rebuilds_and_reloads(): void
    {
        $this->assertSame(0, $this->command(DomainsSetProxy::class, ['--domain' => 'alice.example.test', '--proxy-to' => '3000']));

        $this->assertSame([80 => 'alice:3000', 443 => 'alice:3000'], $this->rules());
        $this->assertSame(['rebuild alice.example.test', 'webserver reload'], $this->host->recorded());
        $this->assertSame([], $this->tunnelSyncs);
        $this->assertStringContainsString('Proxy rules updated; domain vhost rebuilt.', $this->printed);
    }

    public function test_set_proxy_on_a_tunnelled_domain_syncs_the_tunnel_too(): void
    {
        $this->tunnel();

        $this->assertSame(0, $this->command(DomainsSetProxy::class, ['--domain' => 'alice.example.test', '--proxy-to' => '3000', '--port' => ['8080']]));

        $this->assertSame([8080 => 'alice:3000'], $this->rules());
        $this->assertSame(['alice alice.example.test'], $this->tunnelSyncs);
        $this->assertStringContainsString('Proxy rules updated; nginx rebuilt; tunnel ingress synced.', $this->printed);
    }

    public function test_set_proxy_reports_a_failed_tunnel_sync_after_rebuilding_anyway(): void
    {
        $this->tunnel();
        $this->tunnelFailure = new CloudflareException('Cloudflare refused the ingress');

        $this->assertSame(1, $this->command(DomainsSetProxy::class, ['--domain' => 'alice.example.test', '--proxy-to' => '3000']));

        $this->assertSame(['rebuild alice.example.test', 'webserver reload'], $this->host->recorded());
        $this->assertStringContainsString('Cloudflare refused the ingress', $this->printed);
        $this->assertStringContainsString('only the tunnel ingress sync failed.', $this->printed);
    }

    public function test_set_proxy_names_any_other_tunnel_failure(): void
    {
        $this->tunnel();
        $this->tunnelFailure = new RuntimeException('connection reset');

        $this->assertSame(1, $this->command(DomainsSetProxy::class, ['--domain' => 'alice.example.test', '--proxy-to' => '3000']));

        $this->assertStringContainsString('Tunnel ingress sync failed: connection reset', $this->printed);
    }

    private function existingRules(): void
    {
        foreach ([80, 443] as $port) {
            ProxyRule::query()->create([
                'owner_scope' => 'user', 'username' => 'alice', 'transport' => 'http', 'listen_ip' => '*',
                'listen_port' => $port, 'server_name' => 'alice.example.test', 'upstream_host' => 'alice',
                'upstream_port' => 3000, 'upstream_protocol' => 'http', 'enabled' => true,
            ]);
        }
    }

    public function test_unset_proxy_deletes_the_rules_rebuilds_and_reloads(): void
    {
        $this->existingRules();

        $this->assertSame(0, $this->command(DomainsUnsetProxy::class, ['--domain' => 'alice.example.test']));

        $this->assertSame([], $this->rules());
        $this->assertSame(['rebuild alice.example.test', 'webserver reload'], $this->host->recorded());
        $this->assertStringContainsString('Proxy rules removed; domain vhost rebuilt.', $this->printed);
    }

    public function test_unset_proxy_on_a_tunnelled_domain_syncs_the_tunnel_before_rebuilding(): void
    {
        $this->existingRules();
        $this->tunnel();

        $this->assertSame(0, $this->command(DomainsUnsetProxy::class, ['--domain' => 'alice.example.test']));

        $this->assertSame(['alice alice.example.test'], $this->tunnelSyncs);
        $this->assertStringContainsString('Proxy rules removed; tunnel ingress synced; domain vhost rebuilt.', $this->printed);
    }

    public function test_unset_proxy_stops_before_rebuilding_when_the_tunnel_sync_fails(): void
    {
        $this->existingRules();
        $this->tunnel();
        $this->tunnelFailure = new RuntimeException('connection reset');

        $this->assertSame(1, $this->command(DomainsUnsetProxy::class, ['--domain' => 'alice.example.test']));

        $this->assertStringContainsString('Tunnel ingress sync failed: connection reset', $this->printed);
        $this->assertSame([], $this->host->recorded());
        $this->assertSame([], $this->rules());
    }
}
