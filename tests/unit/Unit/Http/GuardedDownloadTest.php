<?php

namespace Tests\Unit\Http;

use App\Lib\Http\GuardedDownload;
use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressRefusedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** The download of a caller's URL: every hop passes the outbound guard, and a bad answer is named. */
class GuardedDownloadTest extends TestCase
{
    private string $into;

    protected function setUp(): void
    {
        parent::setUp();
        $this->into = (string) tempnam(sys_get_temp_dir(), 'pa-guarded-');
        OutboundUrl::setResolverForTests(static fn (string $host): array => [
            str_starts_with($host, 'internal.') ? TestNetwork::LOOPBACK : TestNetwork::routable(4),
        ]);
    }

    protected function tearDown(): void
    {
        OutboundUrl::setResolverForTests(null);
        @unlink($this->into);
        parent::tearDown();
    }

    public function test_a_caller_fetches_a_public_url_and_it_is_requested_once(): void
    {
        Http::fake(['*' => Http::response('archive')]);

        GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into);

        Http::assertSentCount(1);
    }

    public function test_a_caller_naming_a_loopback_host_is_refused_before_any_request(): void
    {
        Http::fake(['*' => Http::response('secret')]);

        $this->assertRefused(fn () => GuardedDownload::fetch('https://internal.example.test/app.zip', $this->into));

        Http::assertNothingSent();
    }

    public function test_a_caller_naming_a_loopback_address_is_refused_before_any_request(): void
    {
        Http::fake(['*' => Http::response('secret')]);

        $this->assertRefused(fn () => GuardedDownload::fetch('http://' . TestNetwork::LOOPBACK . '/app.zip', $this->into));

        Http::assertNothingSent();
    }

    public function test_a_redirect_to_a_loopback_host_is_refused_after_the_first_hop(): void
    {
        Http::fake([
            'downloads.example.test/*' => Http::response('', 302, ['Location' => 'https://internal.example.test/secret']),
            '*' => Http::response('secret'),
        ]);

        $this->assertRefused(fn () => GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into));

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'internal.'));
    }

    public function test_a_relative_redirect_is_followed_on_the_same_host(): void
    {
        Http::fake([
            'downloads.example.test/old/app.zip' => Http::response('', 302, ['Location' => '/new/app.zip']),
            'downloads.example.test/new/app.zip' => Http::response('archive'),
        ]);

        GuardedDownload::fetch('https://downloads.example.test/old/app.zip', $this->into);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://downloads.example.test/new/app.zip');
    }

    public function test_an_endless_redirect_chain_stops_after_the_redirect_limit(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://downloads.example.test/again'])]);

        try {
            GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into);
            $this->fail('a redirect loop was followed forever');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('followed more than ' . OutboundUrl::MAX_REDIRECTS . ' redirects', $e->getMessage());
        }

        Http::assertSentCount(OutboundUrl::MAX_REDIRECTS + 1);
    }

    public function test_a_redirect_without_a_location_is_named(): void
    {
        Http::fake(['*' => Http::response('', 302)]);

        try {
            GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into);
            $this->fail('a redirect without a Location was accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Downloading https://downloads.example.test/app.zip answered HTTP 302 with no Location.', $e->getMessage());
        }
    }

    public function test_an_error_answer_is_named_with_its_status(): void
    {
        Http::fake(['*' => Http::response('nope', 404)]);

        try {
            GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into);
            $this->fail('a 404 was accepted as a file');
        } catch (RuntimeException $e) {
            $this->assertSame('Downloading https://downloads.example.test/app.zip answered HTTP 404.', $e->getMessage());
        }
    }

    public function test_a_file_larger_than_the_limit_is_refused(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 2048))]);

        try {
            GuardedDownload::fetch('https://downloads.example.test/app.zip', $this->into, 1024);
            $this->fail('a file past the limit was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('The file at https://downloads.example.test/app.zip is larger than the 0.0009765625 MB limit.', $e->getMessage());
        }
    }

    private function assertRefused(callable $fetch): void
    {
        try {
            $fetch();
            $this->fail('the URL was not refused');
        } catch (EgressRefusedException $e) {
            $this->assertSame('host_refused', EgressRefusedException::CODE);
        }
    }
}
