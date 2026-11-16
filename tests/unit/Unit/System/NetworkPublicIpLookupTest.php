<?php

namespace Tests\Unit\System;

use App\Models\Ipv4NatMap;
use App\Models\Setting;
use App\System\Network;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\NatHost;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/** How `rebuildIpv4NatMaps()` asks the lookup service for each local address's public one. */
class NetworkPublicIpLookupTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::clearRuntimeSettings();
    }

    public function test_the_lookup_is_curl_on_the_host_bound_to_the_local_address(): void
    {
        $host = $this->hostAnswering(FakeProcess::ok(TestNetwork::public(7) . "\n"));

        $result = (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip');

        $this->assertSame([[
            'curl', '--silent', '--show-error', '--fail', '--ipv4', '--proto', '=http,https', '--max-time', '10',
            '--interface', TestNetwork::private(5), '--url', 'https://lookup.test/ip?local_ip=' . urlencode(TestNetwork::private(5)),
        ]], $host->lookups);
        $this->assertCount(1, $result['maps']);
        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_url_with_a_query_is_used_as_it_is(): void
    {
        $host = $this->hostAnswering(FakeProcess::ok(TestNetwork::public(7)));

        (new Network($host))->rebuildIpv4NatMaps('https://lookup.test/ip?format=text');

        $this->assertSame('https://lookup.test/ip?format=text', end($host->lookups[0]));
        $this->assertSame([TestNetwork::private(5) => TestNetwork::public(7)], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_a_failed_answer_maps_nothing(): void
    {
        $result = $this->rebuild(FakeProcess::failed('curl: (22) The requested URL returned error: 503', 22));

        $this->assertSame([], $result['maps']);
        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
    }

    public function test_an_answer_that_is_not_an_ipv4_address_maps_nothing(): void
    {
        $this->assertSame([], $this->rebuild(FakeProcess::ok('<html>rate limited</html>'))['maps']);
    }

    #[DataProvider('unusableAnswers')]
    public function test_a_loopback_or_unspecified_answer_maps_nothing(string $answer): void
    {
        $this->assertSame([], $this->rebuild(FakeProcess::ok($answer))['maps']);
        $this->assertSame([], Ipv4NatMap::getLocalToPublicMap());
    }

    /** @return array<string, array{string}> */
    public static function unusableAnswers(): array
    {
        return ['loopback' => [TestNetwork::LOOPBACK], 'unspecified' => [TestNetwork::ANY]];
    }

    public function test_the_local_address_itself_is_not_a_mapping(): void
    {
        $this->assertSame([], $this->rebuild(FakeProcess::ok(TestNetwork::private(5)))['maps']);
    }

    public function test_an_unreachable_service_is_logged_and_maps_nothing(): void
    {
        Log::spy();

        $result = $this->rebuild(FakeProcess::failed("curl: (7) Failed to connect to lookup.test port 443: Connection refused\n", 7));

        $this->assertSame([], $result['maps']);
        Log::shouldHaveReceived('warning')->once()->with('Could not lookup public IP for local IP', [
            'local_ip' => TestNetwork::private(5),
            'lookup_url' => 'https://lookup.test/ip',
            'exception_message' => 'curl: (7) Failed to connect to lookup.test port 443: Connection refused',
        ]);
    }

    /** @return array{maps: array<Ipv4NatMap>, default_ipv4_replaced: bool, default_ipv4_previous: ?string, default_ipv4_current: ?string} */
    private function rebuild(Process $answer): array
    {
        return (new Network($this->hostAnswering($answer)))->rebuildIpv4NatMaps('https://lookup.test/ip');
    }

    private function hostAnswering(Process $answer): NatHost
    {
        return (new NatHost)
            ->withInterface('eth0', TestNetwork::private(5))
            ->answering(TestNetwork::private(5), $answer);
    }
}
