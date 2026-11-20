<?php

namespace Tests\Integration\Integrations\Tunnels;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Runtime;
use App\System\Project\SitePasswordProtection;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A Cloudflare tunnel hostname from attach to teardown: the account and tunnel the engine finds or
 * creates, the ingress it pushes from the domain's proxy rules, and the connector it configures.
 */
class CloudflareTunnelLifecycleTest extends TestCase
{
    use InMemoryDatabase;

    private const API = 'https://api.cloudflare.com/client/v4';

    private const HOSTNAME = 'www.example.test';

    private const CREDENTIALS = ['cloudflare_api_token' => 'cf-token-not-real', 'cloudflare_account_id' => 'acc'];

    private const EXISTING_TUNNEL = ['cloudflare_tunnel_id' => 'tun', 'cloudflare_tunnel_token' => 'tun-token'];

    private ServiceManager&MockInterface $services;

    /** @var array<string, mixed> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        $this->services = Mockery::mock(ServiceManager::class);
        Http::fake(fn (Request $request): PromiseInterface => $this->answer($request));
    }

    public function test_a_project_without_an_api_token_has_no_client(): void
    {
        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("Project 'shop' has no Cloudflare API token");

        Cloudflare::clientFor($this->user([]));
    }

    public function test_an_empty_api_token_is_refused_before_cloudflare_is_asked(): void
    {
        try {
            Cloudflare::setApiToken($this->user([]), '   ');
            $this->fail('an empty token must be refused');
        } catch (CloudflareException $e) {
            $this->assertSame('Cloudflare API token must not be empty.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_existing_tunnel_is_adopted_and_its_account_stored(): void
    {
        $this->answers = [
            'GET /accounts' => [['id' => 'acc-9', 'name' => 'Shop Ltd']],
            'GET /accounts/acc-9/cfd_tunnel' => [['id' => 'tun-1', 'name' => 'panelalpha-shop']],
            'GET /accounts/acc-9/cfd_tunnel/tun-1/token' => 'tok-1',
        ];
        $this->services->shouldReceive('configure')->once()->with('cloudflared', true, ['TUNNEL_TOKEN' => 'tok-1']);
        $this->services->shouldReceive('apply')->once()->with('cloudflared');
        $user = $this->user(['cloudflare_api_token' => 'cf-token-not-real']);

        $result = Cloudflare::ensureTunnel($user);

        $this->assertSame(['tunnel_id' => 'tun-1', 'tunnel_token' => 'tok-1', 'account_id' => 'acc-9'], $result);
        $stored = User::query()->findOrFail($user->id);
        $this->assertSame('acc-9', $stored->getCloudflareAccountId());
        $this->assertSame('tun-1', $stored->getCloudflareTunnelId());
        $this->assertSame('tok-1', $stored->getCloudflareTunnelToken());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_a_tunnel_is_created_when_none_carries_the_projects_name(): void
    {
        $this->answers = [
            'GET /accounts/acc/cfd_tunnel' => [['id' => 'other', 'name' => 'panelalpha-someone-else']],
            'POST /accounts/acc/cfd_tunnel' => ['id' => 'tun-2', 'token' => 'tok-2'],
        ];
        $this->services->shouldReceive('configure')->once()->with('cloudflared', true, ['TUNNEL_TOKEN' => 'tok-2']);
        $this->services->shouldReceive('apply')->once();

        $result = Cloudflare::ensureTunnel($this->user(self::CREDENTIALS));

        $this->assertSame(['tunnel_id' => 'tun-2', 'tunnel_token' => 'tok-2', 'account_id' => 'acc'], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['name'] === 'panelalpha-shop'
            && $request['config_src'] === 'cloudflare');
    }

    public function test_attaching_a_hostname_records_its_zone_and_record_and_routes_it_to_the_upstream(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->rule('shop.example.test', 443, 8080);

        $tunnel = Cloudflare::createHostname($user, $domain, self::HOSTNAME);

        $this->assertTrue($tunnel->exists);
        $this->assertSame(Tunnel::PROVIDER_CLOUDFLARE, $tunnel->provider);
        $stored = Tunnel::query()->findOrFail($tunnel->id);
        $this->assertSame(['cloudflare_zone_id' => 'zone-id', 'cloudflare_dns_record_id' => 'rec-1'], $stored->getDetails());
        $this->assertSame([
            ['hostname' => 'old.example.test', 'service' => 'http://'.TestNetwork::LOOPBACK.':9000'],
            ['hostname' => self::HOSTNAME, 'service' => 'http://'.TestNetwork::LOOPBACK.':8080', 'originRequest' => []],
            ['service' => 'http_status:404'],
        ], $this->pushedIngress());
    }

    public function test_a_password_protected_site_is_hairpinned_through_its_https_vhost(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL + $this->passwordOn());
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $listen = TestNetwork::public(7);
        $this->rule('shop.example.test', 443, 8080, $listen);
        $this->rule('shop.example.test', 80, 8080);
        $this->tunnel($user, $domain, self::HOSTNAME, ['cloudflare_zone_id' => 'zone-id']);

        Cloudflare::syncFromProxyRules($user, $domain);

        $this->assertSame([
            'hostname' => self::HOSTNAME,
            'service' => "https://{$listen}:443",
            'originRequest' => [
                'httpHostHeader' => 'shop.example.test',
                'originServerName' => 'shop.example.test',
                'noTLSVerify' => true,
            ],
        ], $this->pushedIngress()[1]);
    }

    public function test_a_password_protected_site_without_tls_is_hairpinned_through_an_account_address(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL + $this->passwordOn());
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $domain->setDetails(['ssl_disabled' => true]);
        $domain->save();
        $this->rule('shop.example.test', 80, 8080);
        $this->tunnel($user, $domain, self::HOSTNAME, ['cloudflare_zone_id' => 'zone-id']);

        Cloudflare::syncFromProxyRules($user, $domain);

        $bind = TestNetwork::private(5);
        $this->assertSame([
            'hostname' => self::HOSTNAME,
            'service' => "http://{$bind}:80",
            'originRequest' => ['httpHostHeader' => 'shop.example.test'],
        ], $this->pushedIngress()[1]);
    }

    public function test_a_tunnel_without_a_stored_zone_looks_its_zone_up_and_keeps_it(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->rule('shop.example.test', 8443, 3000);
        $tunnel = $this->tunnel($user, $domain, self::HOSTNAME, []);

        Cloudflare::syncFromProxyRules($user, $domain);

        $this->assertSame(
            ['cloudflare_zone_id' => 'zone-id', 'cloudflare_dns_record_id' => 'rec-1'],
            Tunnel::query()->findOrFail($tunnel->id)->getDetails()
        );
        $this->assertSame('http://'.TestNetwork::LOOPBACK.':3000', $this->pushedIngress()[1]['service']);
    }

    public function test_an_upstream_port_out_of_range_is_refused_and_nothing_is_pushed(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->rule('shop.example.test', 443, 0);
        $this->tunnel($user, $domain, self::HOSTNAME, ['cloudflare_zone_id' => 'zone-id']);

        try {
            Cloudflare::syncFromProxyRules($user, $domain);
            $this->fail('port 0 must be refused');
        } catch (CloudflareException $e) {
            $this->assertSame('Upstream port must be 1-65535.', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_a_domain_without_enabled_rules_drops_its_hostnames_from_the_ingress(): void
    {
        $this->answers = $this->attachAnswers();
        $this->services->shouldReceive('configure', 'apply');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->rule('shop.example.test', 443, 8080, enabled: false);
        $this->tunnel($user, $domain, 'old.example.test', ['cloudflare_zone_id' => 'zone-id']);

        Cloudflare::syncFromProxyRules($user, $domain);

        $this->assertSame([['service' => 'http_status:404']], $this->pushedIngress());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'dns_records'));
    }

    public function test_a_domain_with_only_panelalpha_tunnels_is_left_alone(): void
    {
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'shop.panelalpha.online',
            'details' => [],
        ]);
        $this->services->shouldNotReceive('configure');

        Cloudflare::syncFromProxyRules($user, $domain);

        Http::assertNothingSent();
    }

    public function test_teardown_without_a_tunnel_on_record_calls_nothing(): void
    {
        Cloudflare::teardownHostname($this->user(self::CREDENTIALS), new Tunnel(['hostname' => self::HOSTNAME]));

        Http::assertNothingSent();
    }

    public function test_teardown_finds_a_record_it_did_not_store_and_removes_the_hostname_from_the_ingress(): void
    {
        $this->answers = [
            'GET /zones/zone-id/dns_records' => [['id' => 'found-rec', 'type' => 'CNAME', 'name' => self::HOSTNAME]],
            'DELETE /zones/zone-id/dns_records/found-rec' => ['id' => 'found-rec'],
            'GET /accounts/acc/cfd_tunnel/tun/configurations' => $this->ingressWith(self::HOSTNAME),
            'PUT /accounts/acc/cfd_tunnel/tun/configurations' => [],
        ];
        $tunnel = new Tunnel(['hostname' => self::HOSTNAME, 'details' => ['cloudflare_zone_id' => 'zone-id']]);

        Cloudflare::teardownHostname($this->user(self::CREDENTIALS + self::EXISTING_TUNNEL), $tunnel);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::API.'/zones/zone-id/dns_records/found-rec');
        $this->assertSame([
            ['hostname' => 'old.example.test', 'service' => 'http://'.TestNetwork::LOOPBACK.':9000'],
            ['service' => 'http_status:404'],
        ], $this->pushedIngress());
    }

    public function test_teardown_with_no_record_in_the_zone_deletes_nothing(): void
    {
        $this->answers = [
            'GET /zones/zone-id/dns_records' => [],
            'GET /accounts/acc/cfd_tunnel/tun/configurations' => $this->ingressWith(),
            'PUT /accounts/acc/cfd_tunnel/tun/configurations' => [],
        ];
        $tunnel = new Tunnel(['hostname' => self::HOSTNAME, 'details' => ['cloudflare_zone_id' => 'zone-id']]);

        Cloudflare::teardownHostname($this->user(self::CREDENTIALS + self::EXISTING_TUNNEL), $tunnel);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_project_teardown_deletes_every_hostname_the_tunnel_and_the_stored_credentials(): void
    {
        $this->answers = [
            'DELETE /zones/zone-id/dns_records/rec-a' => [],
            'GET /accounts/acc/cfd_tunnel/tun/configurations' => $this->ingressWith(),
            'PUT /accounts/acc/cfd_tunnel/tun/configurations' => [],
            'DELETE /accounts/acc/cfd_tunnel/tun' => [],
        ];
        $this->services->shouldReceive('configure')->once()->with('cloudflared', false, []);
        $this->services->shouldReceive('apply')->once()->with('cloudflared');
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL + ['keep' => 'me']);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->tunnel($user, $domain, 'a.example.test', ['cloudflare_zone_id' => 'zone-id', 'cloudflare_dns_record_id' => 'rec-a']);

        Cloudflare::teardownProject($user);

        $this->assertSame(0, Tunnel::query()->count());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === self::API.'/accounts/acc/cfd_tunnel/tun');
        $stored = User::query()->findOrFail($user->id);
        $this->assertNull($stored->getCloudflareApiToken());
        $this->assertNull($stored->getCloudflareTunnelId());
        $this->assertSame('me', $stored->getDetails()['keep']);
    }

    public function test_project_teardown_still_clears_state_when_cloudflare_refuses_the_tunnel_delete(): void
    {
        $this->answers = ['DELETE /accounts/acc/cfd_tunnel/tun' => self::refusal(500, 'Internal Server Error')];
        $this->services->shouldReceive('configure', 'apply');
        Log::spy();
        $user = $this->user(self::CREDENTIALS + self::EXISTING_TUNNEL);

        Cloudflare::teardownProject($user);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'Cloudflare tunnel teardown failed for shop')
        );
        $this->assertNull(User::query()->findOrFail($user->id)->getCloudflareTunnelId());
    }

    public function test_a_tunnel_row_that_will_not_delete_is_logged_and_deleted_on_the_second_try(): void
    {
        $this->services->shouldReceive('configure', 'apply');
        Log::spy();
        $user = $this->user([]);
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $this->tunnel($user, $domain, 'a.example.test', []);
        $refusals = 1;
        Tunnel::deleting(function () use (&$refusals): void {
            if ($refusals-- > 0) {
                throw new \RuntimeException('row locked');
            }
        });

        Cloudflare::teardownProject($user);

        $this->assertSame(0, Tunnel::query()->count());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => $message === 'Cloudflare tunnel teardown failed for a.example.test: row locked'
        );
        Http::assertNothingSent();
    }

    public function test_clearing_the_connector_state_survives_a_connector_that_cannot_be_stopped(): void
    {
        Log::spy();
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andThrow(new \RuntimeException('account down'));
        $user = $this->userOn($project, self::CREDENTIALS + self::EXISTING_TUNNEL);

        Cloudflare::clearConnectorState($user);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => $message === 'Could not stop cloudflared for shop: account down'
        );
        $this->assertSame([], User::query()->findOrFail($user->id)->getDetails());
    }

    public function test_a_connector_rendered_without_a_token_uses_the_stored_one(): void
    {
        $this->services->shouldReceive('configure')->once()->with('cloudflared', true, ['TUNNEL_TOKEN' => 'tun-token']);

        Cloudflare::renderConnector($this->user(self::EXISTING_TUNNEL), true);
    }

    public function test_a_connector_with_no_token_anywhere_is_rendered_off(): void
    {
        $this->services->shouldReceive('configure')->once()->with('cloudflared', false, []);

        Cloudflare::renderConnector($this->user([]), true, '  ');
    }

    public function test_stopping_the_connector_renders_it_off_and_applies_it(): void
    {
        $this->services->shouldReceive('configure')->once()->with('cloudflared', false, []);
        $this->services->shouldReceive('apply')->once()->with('cloudflared');

        Cloudflare::stopConnector($this->user(self::EXISTING_TUNNEL));
    }

    public function test_a_connector_that_cannot_be_applied_is_only_logged(): void
    {
        Log::spy();
        $this->services->shouldReceive('apply')->andThrow(new \RuntimeException('container stopped'));

        Cloudflare::reloadConnector($this->user([]));

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message): bool => $message === 'cloudflared reload skipped for shop: container stopped'
        );
    }

    public function test_the_connector_cannot_start_without_a_tunnel_token(): void
    {
        $this->services->shouldNotReceive('configure');
        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("Project 'shop' has no Cloudflare tunnel token; call ensureTunnel first.");

        Cloudflare::ensureConnectorRunning($this->user([]));
    }

    public function test_a_project_that_is_not_dind_has_no_connector_to_touch(): void
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn(Mockery::mock(Runtime::class));
        $user = $this->userOn($project, self::EXISTING_TUNNEL);

        Cloudflare::renderConnector($user, true);
        Cloudflare::reloadConnector($user);
        Cloudflare::disableConnector($user);

        $this->expectException(CloudflareException::class);
        $this->expectExceptionMessage("only supported for DinD projects ('shop' is not DinD)");
        Cloudflare::assertDinD($user);
    }

    /** @return array<string, mixed> */
    private function attachAnswers(): array
    {
        return [
            'GET /zones' => fn (Request $request): array => ($request->data()['name'] ?? '') === 'example.test'
                ? [['id' => 'zone-id', 'name' => 'example.test']]
                : [],
            'GET /zones/zone-id/dns_records' => [],
            'POST /zones/zone-id/dns_records' => ['id' => 'rec-1', 'name' => self::HOSTNAME],
            'GET /accounts/acc/cfd_tunnel/tun/configurations' => $this->ingressWith(),
            'PUT /accounts/acc/cfd_tunnel/tun/configurations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function ingressWith(string ...$hostnames): array
    {
        $ingress = [['hostname' => 'old.example.test', 'service' => 'http://'.TestNetwork::LOOPBACK.':9000']];
        foreach ($hostnames as $hostname) {
            $ingress[] = ['hostname' => $hostname, 'service' => 'http://'.TestNetwork::LOOPBACK.':1'];
        }
        $ingress[] = ['service' => 'http_status:404'];

        return ['config' => ['ingress' => $ingress]];
    }

    /** @return list<array<string, mixed>> */
    private function pushedIngress(): array
    {
        $puts = Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/configurations'));
        $this->assertCount(1, $puts);

        return json_decode($puts->first()[0]->body(), true)['config']['ingress'];
    }

    private function answer(Request $request): PromiseInterface
    {
        $path = substr((string) strtok($request->url(), '?'), strlen(self::API));
        $answer = $this->answers[$request->method().' '.$path] ?? null;
        if ($answer === null) {
            return self::refusal(404, "no fake for {$request->method()} {$path}");
        }
        if ($answer instanceof PromiseInterface) {
            return $answer;
        }

        return Http::response(['success' => true, 'result' => is_callable($answer) ? $answer($request) : $answer]);
    }

    private static function refusal(int $status, string $message): PromiseInterface
    {
        return Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => $message]]], $status);
    }

    /** @return array<string, mixed> */
    private function passwordOn(): array
    {
        return [SitePasswordProtection::DETAIL_ENABLED => true, SitePasswordProtection::DETAIL_HASH => 'hash'];
    }

    private function rule(string $serverName, int $listenPort, int $upstreamPort, string $listenIp = '*', bool $enabled = true): void
    {
        ProxyRule::create([
            'owner_scope' => 'user',
            'username' => 'shop',
            'enabled' => $enabled,
            'transport' => 'http',
            'listen_ip' => $listenIp,
            'listen_port' => $listenPort,
            'server_name' => $serverName,
            'upstream_host' => 'app',
            'upstream_port' => $upstreamPort,
        ]);
    }

    /** @param array<string, string> $details */
    private function tunnel(User $user, Domain $domain, string $hostname, array $details): Tunnel
    {
        return Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => $hostname,
            'details' => $details,
        ]);
    }

    /**
     * A saved DinD project with no home directory: the legacy token-file cleanup fails on its own
     * try/catch instead of running a command on this machine.
     *
     * @param  array<string, mixed>  $details
     */
    private function user(array $details): User
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('services')->andReturn($this->services);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($dind);

        return $this->userOn($project, $details);
    }

    /** @param  array<string, mixed>  $details */
    private function userOn(Project $project, array $details): User
    {
        $user = new class extends User
        {
            protected $table = 'users';

            public Project $fakeProject;

            public function project(?System $system = null): Project
            {
                return $this->fakeProject;
            }

            public function getBindIpAddresses(): array
            {
                return ['ipv4' => [TestNetwork::private(5)], 'ipv6' => []];
            }
        };
        $user->fakeProject = $project;
        $user->username = 'shop';
        $user->domain = 'shop.example.test';
        $user->details = $details;
        $user->save();

        return $user;
    }
}
