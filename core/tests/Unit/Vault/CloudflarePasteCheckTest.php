<?php

namespace Tests\Unit\Vault;

use App\Lib\Vault\PasteCheck;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * engine#7 for Cloudflare: the form runs the calls the tunnel setup makes
 * (resolveAccount, the tunnel list, the zone lookup) before storing a token.
 */
class CloudflarePasteCheckTest extends VaultTestCase
{
    private const TOKEN = 'cf_test_token_0123456789abcdef0123456789';

    /** @var array<string, array{0: int, 1: array<string, mixed>}> */
    private array $routes = [];

    /** @param array<string, array{0: int, 1: array<string, mixed>}> $routes path prefix => [status, body] */
    private function cloudflare(array $routes): void
    {
        // One fake for the whole test: a second Http::fake() would sit behind the first.
        $first = $this->routes === [];
        $this->routes = $routes;
        if (!$first) {
            return;
        }
        Http::fake(function (Request $request) {
            $routes = $this->routes;
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            foreach ($routes as $prefix => [$status, $body]) {
                if (str_starts_with($path, '/client/v4' . $prefix)) {
                    return Http::response($body, $status);
                }
            }

            return Http::response(['success' => false, 'errors' => [['message' => 'unrouted ' . $path]]], 404);
        });
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>}> */
    private function workingToken(): array
    {
        return [
            '/accounts/acc1/cfd_tunnel' => [200, ['success' => true, 'result' => []]],
            '/accounts' => [200, ['success' => true, 'result' => [['id' => 'acc1', 'name' => 'Acme']]]],
            '/zones' => [200, ['success' => true, 'result' => [
                ['id' => 'z1', 'name' => 'example.com', 'account' => ['id' => 'acc1', 'name' => 'Acme']],
            ]]],
        ];
    }

    private function refused(): array
    {
        return [400, ['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]]];
    }

    /** @param array<string, string>|null $verifyWith */
    private function cloudflareEntry(?array $verifyWith = null): array
    {
        return $this->entry(['type' => SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN, 'verify_with' => $verifyWith]);
    }

    public function test_a_working_token_is_saved_as_verified(): void
    {
        $this->cloudflare($this->workingToken());
        [$entry, $ref] = $this->cloudflareEntry();

        $response = $this->post('/vault/' . $ref, ['secret' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Checked: this token can read Cloudflare account Acme');
        $this->assertTrue($entry->refresh()->isSealed());
        $this->assertSame('verified', $entry->verification['result']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
    }

    public function test_a_rejected_token_is_not_saved_and_the_link_stays_open(): void
    {
        $this->cloudflare(['/accounts' => $this->refused(), '/zones' => $this->refused()]);
        [$entry, $ref] = $this->cloudflareEntry();

        $response = $this->post('/vault/' . $ref, ['secret' => 'cf_wrong']);

        $response->assertStatus(422);
        $response->assertSee('Not saved: Cloudflare rejected this API token');
        $response->assertSee('name="secret"', false);
        $this->assertFalse($entry->refresh()->isSealed());

        $this->cloudflare($this->workingToken());
        $this->post('/vault/' . $ref, ['secret' => self::TOKEN])->assertOk();
        $this->assertSame(self::TOKEN, $entry->refresh()->revealSecret());
    }

    public function test_a_token_without_tunnel_access_is_refused(): void
    {
        $this->cloudflare([
            '/accounts/acc1/cfd_tunnel' => [403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]],
        ] + $this->workingToken());
        [$entry, $ref] = $this->cloudflareEntry();

        $response = $this->post('/vault/' . $ref, ['secret' => self::TOKEN]);

        $response->assertStatus(422);
        $response->assertSee('cannot manage Cloudflare Tunnels');
        $this->assertFalse($entry->refresh()->isSealed());
    }

    public function test_the_hostname_zone_must_be_visible(): void
    {
        $this->cloudflare($this->workingToken());
        [, $ok] = $this->cloudflareEntry(['hostname' => 'shop.example.com']);
        [$other, $missing] = $this->cloudflareEntry(['hostname' => 'shop.other.org']);

        $this->post('/vault/' . $ok, ['secret' => self::TOKEN])
            ->assertOk()->assertSee('Cloudflare account Acme, zone example.com');

        $this->post('/vault/' . $missing, ['secret' => self::TOKEN])
            ->assertStatus(422)->assertSee('is not under any Cloudflare zone');
        $this->assertFalse($other->refresh()->isSealed());
    }

    public function test_cloudflare_unreachable_saves_unchecked(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        [$entry, $ref] = $this->cloudflareEntry();

        $this->post('/vault/' . $ref, ['secret' => self::TOKEN])->assertOk()->assertSee('Saved without a check');

        $this->assertTrue($entry->refresh()->isSealed());
        $this->assertSame('unchecked', $entry->verification['result']);
    }

    public function test_a_cloudflare_server_error_saves_unchecked(): void
    {
        $this->cloudflare(['/accounts' => [502, []], '/zones' => [502, []]]);
        [$entry, $ref] = $this->cloudflareEntry();

        $this->post('/vault/' . $ref, ['secret' => self::TOKEN])->assertOk();

        $this->assertSame('unchecked', $entry->refresh()->verification['result']);
    }

    public function test_the_form_says_what_it_checks(): void
    {
        [, $plain] = $this->cloudflareEntry();
        [, $zoned] = $this->cloudflareEntry(['hostname' => 'shop.example.com']);

        $this->get('/vault/' . $plain)->assertSee('tried against your Cloudflare account.')->assertSee('Check and save');
        $this->get('/vault/' . $zoned)->assertSee('your Cloudflare account and the zone for shop.example.com');
    }

    public function test_mint_takes_a_hostname_and_nothing_else(): void
    {
        $this->assertSame(
            ['hostname' => 'shop.example.com'],
            PasteCheck::prepare('cloudflare_api_token', ['hostname' => ' Shop.Example.com '])
        );

        foreach ([['hostname' => 'not a host'], ['hostname' => 'localhost'], ['repo_url' => 'https://github.com/a/b']] as $bad) {
            try {
                PasteCheck::prepare('cloudflare_api_token', $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
