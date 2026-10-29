<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The client address Laravel works out. PmaSso used to decide on it and now
 * checks the TCP peer instead (PmaSsoTest); rate limits still key on it.
 */
class ClientAddressTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function test_a_public_client_cannot_claim_an_internal_address(): void
    {
        // The engine's nginx appends the true peer to X-Forwarded-For, so the
        // rightmost entry is the one that counts. TrustProxies no longer trusts
        // all of RFC-1918, so a caller inside it cannot prepend a second
        // address and have Laravel believe it.
        $request = $this->throughTrustProxies($this->requestFrom(TestNetwork::routable(8), TestNetwork::private(5).', '.TestNetwork::routable(8)));

        $this->assertSame(TestNetwork::routable(8), $request->ip());
    }

    public function test_an_internal_client_cannot_relabel_itself_either(): void
    {
        $request = $this->throughTrustProxies($this->requestFrom(TestNetwork::container(9), TestNetwork::public(7).', '.TestNetwork::container(9)));

        // Still treated as the container it really is, not as the public
        // address it named.
        $this->assertSame(TestNetwork::container(9), $request->ip());
    }

    public function test_the_loopback_hop_is_the_only_trusted_proxy(): void
    {
        $this->assertSame(
            [TestNetwork::LOOPBACK, '::1'],
            (fn () => $this->proxies)->call(new TrustProxies())
        );
    }

    private function requestFrom(string $remoteAddr, ?string $forwardedFor = null): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return Request::create('/api/mysql/phpmyadmin-sso-token', 'PUT', [], [], [], $server);
    }

    private function throughTrustProxies(Request $request): Request
    {
        (new TrustProxies())->handle($request, static fn (Request $r) => new Response('', 204));

        return $request;
    }
}
