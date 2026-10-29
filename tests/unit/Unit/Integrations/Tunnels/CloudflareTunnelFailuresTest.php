<?php

namespace Tests\Unit\Integrations\Tunnels;

use App\Exceptions\ProblemException;
use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\Cloudflare\CloudflareFailure;
use App\Lib\Apis\Cloudflare\ZoneNotFoundException;
use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\Services\ServiceManager;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Attaching and removing a Cloudflare tunnel hostname: a refusal names the token permission the
 * failing step needs, and a token that cannot read the zone is refused before any tunnel exists.
 */
class CloudflareTunnelFailuresTest extends TestCase
{
    private const HOSTNAME = 'www.example.test';

    private const ZONES = 'api.cloudflare.com/client/v4/zones?*';

    private const DNS_RECORDS = 'api.cloudflare.com/client/v4/zones/zone-id/dns_records*';

    private const TUNNELS = 'api.cloudflare.com/client/v4/accounts/acc/cfd_tunnel*';

    private const CREDENTIALS = ['cloudflare_api_token' => 'cf-token-not-real', 'cloudflare_account_id' => 'acc'];

    private const EXISTING_TUNNEL = ['cloudflare_tunnel_id' => 'tun', 'cloudflare_tunnel_token' => 'tun-token'];

    private ServiceManager&MockInterface $services;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->services = Mockery::mock(ServiceManager::class);
    }

    public function test_a_token_that_cannot_read_the_zone_is_refused_before_any_tunnel_exists(): void
    {
        Http::fake([self::ZONES => self::refusal(403, 9109, 'Unauthorized to access requested resource')]);
        $this->services->shouldNotReceive('configure');
        $user = $this->user(self::CREDENTIALS);

        $problem = $this->problemFrom(fn () => $this->attach($user));

        $this->assertSame('cloudflare_permission_denied', $problem['code']);
        $this->assertSame('cloudflare_api_token', $problem['field']);
        $this->assertStringContainsString('Zone · Zone · Read permission', $problem['message']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cfd_tunnel'));
        $this->assertNull($user->getCloudflareTunnelId());
    }

    public function test_a_zone_the_token_cannot_see_keeps_its_own_message_and_creates_no_tunnel(): void
    {
        Http::fake([self::ZONES => Http::response(['success' => true, 'result' => []])]);
        $this->services->shouldNotReceive('configure');

        try {
            $this->attach($this->user(self::CREDENTIALS));
            $this->fail('a hostname outside every visible zone must be refused');
        } catch (CloudflareException $e) {
            $this->assertInstanceOf(ZoneNotFoundException::class, $e);
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cfd_tunnel'));
    }

    public function test_a_token_refused_the_tunnel_names_the_tunnel_permission(): void
    {
        Http::fake([
            self::ZONES => self::zone(),
            self::TUNNELS => self::refusal(403, 10000, 'Authentication error'),
        ]);
        $this->services->shouldNotReceive('configure');
        $user = $this->user(self::CREDENTIALS);

        $problem = $this->problemFrom(fn () => $this->attach($user));

        $this->assertSame('cloudflare_permission_denied', $problem['code']);
        $this->assertStringContainsString('Account · Cloudflare Tunnel · Edit permission for this account', $problem['message']);
        $this->assertNull($user->getCloudflareTunnelId());
    }

    public function test_a_token_without_dns_edit_names_it_instead_of_cloudflares_authentication_error(): void
    {
        Http::fake([
            self::ZONES => self::zone(),
            self::DNS_RECORDS => self::refusal(403, 10000, 'Authentication error'),
        ]);
        $this->services->shouldReceive('configure', 'apply');

        $problem = $this->problemFrom(fn () => $this->attach($this->user(self::CREDENTIALS + self::EXISTING_TUNNEL)));

        $this->assertSame('cloudflare_permission_denied', $problem['code']);
        $this->assertStringContainsString('Zone · DNS · Edit permission for this zone', $problem['message']);
        $this->assertStringNotContainsString('Authentication error', $problem['message']);
        $this->assertStringContainsString('/zones?', Http::recorded()[0][0]->url());
    }

    /** @return array<string, array{\Closure(): PromiseInterface, int, string}> */
    public static function failuresOnTheZoneLookup(): array
    {
        return [
            'invalid token' => [fn () => self::refusal(401, 1000, 'Invalid API Token'), 422, 'cloudflare_token_invalid'],
            'refused request' => [fn () => self::refusal(400, 7003, 'Could not route to /zones'), 422, 'cloudflare_request_refused'],
            'rate limited' => [fn () => self::refusal(429, 971, 'Please wait and consider throttling your request speed'), 503, 'cloudflare_rate_limited'],
            'outage' => [fn () => self::refusal(500, 10000, 'Internal Server Error'), 502, 'cloudflare_unavailable'],
            'unreachable' => [fn () => Http::failedConnection('cURL error 28: Operation timed out'), 502, 'cloudflare_unavailable'],
        ];
    }

    /** @param \Closure(): PromiseInterface $answer */
    #[DataProvider('failuresOnTheZoneLookup')]
    public function test_each_kind_of_failure_answers_as_the_cdn_does(\Closure $answer, int $status, string $code): void
    {
        Http::fake([self::ZONES => $answer]);
        $this->services->shouldNotReceive('configure');

        try {
            $this->attach($this->user(self::CREDENTIALS));
            $this->fail('a failed zone lookup must raise');
        } catch (ProblemException $e) {
            $this->assertSame([$status, $code], [$e->status, $e->problems[0]['code']]);
        } catch (CloudflareFailure $e) {
            $this->assertSame([$status, $code], [$e->httpStatus, $e->problemCode]);
        }
    }

    public function test_a_dns_record_the_token_may_not_delete_is_logged_naming_the_permission(): void
    {
        Http::fake([self::DNS_RECORDS => self::refusal(403, 10000, 'Authentication error')]);
        Log::spy();
        $tunnel = new Tunnel([
            'hostname' => self::HOSTNAME,
            'details' => ['cloudflare_zone_id' => 'zone-id', 'cloudflare_dns_record_id' => 'rec'],
        ]);

        Cloudflare::teardownHostname($this->user(self::CREDENTIALS + self::EXISTING_TUNNEL), $tunnel);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'Zone · DNS · Edit permission')
        );
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'configurations'));
    }

    public function test_an_ingress_the_token_may_not_change_is_logged_naming_the_tunnel_permission(): void
    {
        Http::fake([
            self::DNS_RECORDS => Http::response(['success' => true, 'result' => ['id' => 'rec']]),
            self::TUNNELS => self::refusal(403, 10000, 'Authentication error'),
        ]);
        Log::spy();
        $tunnel = new Tunnel([
            'hostname' => self::HOSTNAME,
            'details' => ['cloudflare_zone_id' => 'zone-id', 'cloudflare_dns_record_id' => 'rec'],
        ]);

        Cloudflare::teardownHostname($this->user(self::CREDENTIALS + self::EXISTING_TUNNEL), $tunnel);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'Account · Cloudflare Tunnel · Edit permission')
        );
    }

    private function attach(User $user): Tunnel
    {
        return Cloudflare::createHostname($user, new Domain(['domain' => 'shop.example.test']), self::HOSTNAME);
    }

    /**
     * A DinD project with no home directory: the legacy token-file cleanup fails on its own
     * try/catch instead of running a command on this machine.
     *
     * @param  array<string, string>  $details
     */
    private function user(array $details): User
    {
        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('services')->andReturn($this->services);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runtime')->andReturn($dind);

        $user = new class extends User
        {
            public Project $fakeProject;

            public function project(?System $system = null): Project
            {
                return $this->fakeProject;
            }
        };
        $user->fakeProject = $project;
        $user->username = 'shop';
        $user->details = $details;

        return $user;
    }

    /**
     * @param  callable(): mixed  $operation
     * @return array<string, mixed>
     */
    private function problemFrom(callable $operation): array
    {
        try {
            $operation();
        } catch (ProblemException $e) {
            return $e->problems[0];
        }

        $this->fail('expected a ProblemException');
    }

    private static function zone(): PromiseInterface
    {
        return Http::response(['success' => true, 'result' => [['id' => 'zone-id', 'name' => 'example.test']]]);
    }

    private static function refusal(int $status, int $code, string $message): PromiseInterface
    {
        return Http::response(['success' => false, 'errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
