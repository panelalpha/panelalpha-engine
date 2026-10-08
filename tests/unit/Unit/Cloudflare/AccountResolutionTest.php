<?php

namespace Tests\Unit\Cloudflare;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which account a tunnel goes into, and what the engine does when it cannot
 * list accounts.
 *
 * `GET /accounts` is not the only place the account id lives, and it is the
 * most privileged one. A token created from Cloudflare's own tunnel guide --
 * `Account: Cloudflare Tunnel Edit` plus `Zone: DNS Edit`, with the account and
 * zone selected under Resources -- can manage tunnels and DNS in an account
 * while being refused the account *object* itself:
 *
 *   GET /accounts                                200  result: []
 *   GET /zones                                   200  one zone
 *   GET /accounts/{id}                           403  9109 Unauthorized
 *   GET /accounts/{id}/cfd_tunnel                200  works
 *
 * The id in that third line came out of the zone's own `account` field. Reading
 * it from there is the difference between a token that works and a token the
 * engine refuses before it has asked it to do anything.
 */
class AccountResolutionTest extends TestCase
{
    private const ACCOUNT = '54390c0589dac7b4a1474b79d7ec7dde';

    private function client(): Cloudflare
    {
        return new Cloudflare('cf-token-not-real');
    }

    /** The documented happy path: the token can list accounts. */
    public function test_the_account_comes_from_the_account_list_when_it_can(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response([
                'success' => true,
                'result' => [['id' => self::ACCOUNT, 'name' => 'Example Account']],
            ]),
        ]);

        $account = $this->client()->resolveAccount();

        $this->assertSame(self::ACCOUNT, $account['id']);
        $this->assertSame('Example Account', $account['name']);
    }

    /**
     * The case a working token hits: `/accounts` is empty or refused, but a
     * zone is visible and it names its account.
     */
    public function test_the_account_is_found_through_a_visible_zone(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'zone-id-1',
                    'name' => 'miodowski.com',
                    'account' => ['id' => self::ACCOUNT, 'name' => 'Example Account'],
                ]],
            ]),
        ]);

        $account = $this->client()->resolveAccount();

        $this->assertSame(self::ACCOUNT, $account['id']);
        $this->assertSame('Example Account', $account['name']);
    }

    /** The same, when listing accounts is refused outright rather than empty. */
    public function test_a_refused_account_list_still_falls_back_to_a_zone(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']],
            ], 403),
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'zone-id-1',
                    'name' => 'miodowski.com',
                    'account' => ['id' => self::ACCOUNT, 'name' => 'Example Account'],
                ]],
            ]),
        ]);

        $this->assertSame(self::ACCOUNT, $this->client()->resolveAccount()['id']);
    }

    /**
     * Neither route works: the message has to name both, because the operator
     * cannot otherwise tell a missing permission from a missing zone.
     */
    public function test_a_token_with_neither_says_what_to_check(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones*' => Http::response(['success' => true, 'result' => []]),
        ]);

        try {
            $this->client()->resolveAccount();
            $this->fail('a token that can see nothing must not resolve an account');
        } catch (CloudflareException $e) {
            $this->assertStringContainsString('Account Resources', $e->getMessage());
            $this->assertStringContainsString('Zone Resources', $e->getMessage());
        }
    }

    /**
     * A zone without an `account` block is not evidence of an id, and guessing
     * one would send tunnel creation at an account the token may not own.
     */
    public function test_a_zone_carrying_no_account_yields_no_id(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts*' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [['id' => 'zone-id-1', 'name' => 'example.com']],
            ]),
        ]);

        $this->expectException(CloudflareException::class);
        $this->client()->resolveAccount();
    }

    /**
     * A token Cloudflare refused is a different problem from one it accepted
     * and scoped narrowly, and only the second is about Account Resources.
     *
     * Both routes used to swallow every CloudflareException, so an expired or
     * mistyped token — the commoner case by far — was answered with a lecture
     * about resource scoping on a token that was never valid.
     */
    public function test_a_rejected_token_says_so_instead_of_lecturing_about_scoping(): void
    {
        Http::fake(['*' => Http::response(
            ['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]],
            401
        )]);

        try {
            $this->client()->resolveAccount();
            $this->fail('an invalid token must not resolve');
        } catch (CloudflareException $e) {
            $this->assertStringContainsString('Cloudflare rejected this API token', $e->getMessage());
            $this->assertStringContainsString('Invalid API Token', $e->getMessage());
            $this->assertStringNotContainsString('Account Resources', $e->getMessage());
        }
    }

    /**
     * The zone route infers the account from a zone, which is sound only while
     * one account is in view.
     *
     * A token that sees zones in several accounts broke it silently: the id
     * came from whichever zone Cloudflare listed first, while
     * findZoneForHostname() picks by longest suffix across all of them. The
     * tunnel would be created in one account and the CNAME written into a zone
     * in another, and the hostname would never route.
     */
    public function test_zones_in_more_than_one_account_are_refused_rather_than_guessed(): void
    {
        Http::fake([
            '*/accounts*' => Http::response(['success' => true, 'result' => []], 200),
            '*/zones*' => Http::response(['success' => true, 'result' => [
                ['id' => 'z1', 'name' => 'alpha.test', 'account' => ['id' => 'acc1', 'name' => 'Alpha']],
                ['id' => 'z2', 'name' => 'beta.test', 'account' => ['id' => 'acc2', 'name' => 'Beta']],
            ]], 200),
        ]);

        try {
            $this->client()->resolveAccount();
            $this->fail('an ambiguous account must not be guessed');
        } catch (CloudflareException $e) {
            $this->assertStringContainsString('more than one Cloudflare account', $e->getMessage());
            $this->assertStringContainsString('Alpha', $e->getMessage());
            $this->assertStringContainsString('Beta', $e->getMessage());
        }
    }

    /** Several zones in the *same* account are not ambiguous. */
    public function test_many_zones_in_one_account_still_resolve(): void
    {
        Http::fake([
            '*/accounts*' => Http::response(['success' => true, 'result' => []], 200),
            '*/zones*' => Http::response(['success' => true, 'result' => [
                ['id' => 'z1', 'name' => 'alpha.test', 'account' => ['id' => self::ACCOUNT, 'name' => 'Alpha']],
                ['id' => 'z2', 'name' => 'beta.test', 'account' => ['id' => self::ACCOUNT, 'name' => 'Alpha']],
            ]], 200),
        ]);

        $this->assertSame(self::ACCOUNT, $this->client()->resolveAccount()['id']);
    }
}
