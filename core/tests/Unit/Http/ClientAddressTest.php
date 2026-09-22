<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\PmaSso;
use App\Http\Middleware\TrustProxies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PmaSso guards an endpoint that hands back MySQL credentials, and the only
 * thing it has to decide on is the client address. Both halves of producing
 * that address had been broken at once, so both are pinned here.
 */
class ClientAddressTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function test_a_public_client_is_refused(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->throughPmaSso($this->requestFrom('8.8.8.8'));
    }

    public function test_an_internal_client_is_allowed(): void
    {
        $response = $this->throughPmaSso($this->requestFrom('10.0.0.5'));

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_a_public_client_cannot_claim_an_internal_address(): void
    {
        // The engine's nginx appends the true peer to X-Forwarded-For, so the
        // rightmost entry is the one that counts. TrustProxies no longer trusts
        // all of RFC-1918, so a caller inside it cannot prepend a second
        // address and have Laravel believe it.
        $request = $this->requestFrom('8.8.8.8', '10.0.0.5, 8.8.8.8');

        $this->expectException(HttpResponseException::class);

        $this->throughPmaSso($this->throughTrustProxies($request));
    }

    public function test_an_internal_client_cannot_relabel_itself_either(): void
    {
        $request = $this->requestFrom('172.17.0.9', '203.0.113.7, 172.17.0.9');

        $response = $this->throughPmaSso($this->throughTrustProxies($request));

        // Still treated as the container it really is, not as the public
        // address it named.
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('172.17.0.9', $request->ip());
    }

    public function test_the_loopback_hop_is_the_only_trusted_proxy(): void
    {
        $this->assertSame(
            ['127.0.0.1', '::1'],
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

    private function throughPmaSso(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return (new PmaSso())->handle($request, static fn (Request $r) => new JsonResponse(null, 204));
    }
}
