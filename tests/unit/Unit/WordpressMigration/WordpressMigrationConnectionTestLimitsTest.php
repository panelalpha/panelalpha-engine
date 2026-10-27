<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;

/**
 * The connection test runs inside an HTTP request, on one of core's few
 * PHP-FPM workers, so it gives up on a silent source after its own short
 * limits, never after the queue job's budget. The silent source is a socket
 * on this host's own address that accepts and never answers.
 */
class WordpressMigrationConnectionTestLimitsTest extends WordpressMigrationTestCase
{
    private const HOST = 'wp.example';

    /** @var resource|null */
    private $silentSource = null;

    protected function tearDown(): void
    {
        if (is_resource($this->silentSource)) {
            fclose($this->silentSource);
        }
        OutboundUrl::setPolicyForTests(null, null);
        parent::tearDown();
    }

    public function test_the_whole_test_gives_up_at_its_deadline_with_one_message(): void
    {
        $http = new RoutedSourceHttpClient;
        $http->on('GET', 'https://example.com/wp-login.php', static function () use ($http): SourceHttpResponse {
            $http->wait(100, static fn () => usleep(100_000), 'https://example.com/wp-login.php');

            return new SourceHttpResponse('late', 200);
        });
        $started = microtime(true);

        $answer = (new SourceProbe(fn () => $http, [], 1))->run(new SourceAccess(url: 'https://example.com', username: 'admin', password: 'secret'));

        $this->assertSame(
            ['ok' => false, 'error_type' => SourceCheckFailed::UNREACHABLE, 'message' => 'The source site did not answer within 1 seconds.'],
            $answer,
        );
        $this->assertLessThan(3, microtime(true) - $started);
    }

    public function test_a_connection_test_request_to_a_silent_source_times_out_on_its_own(): void
    {
        [$url, $resolve] = $this->silentSource();
        $client = CurlSourceHttpClient::forConnectionTest(null, $resolve, 1);
        $started = microtime(true);

        try {
            $client->request('GET', $url.'/wp-login.php');
            $this->fail('A silent source must time out.');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNREACHABLE, $e->errorType());
        }
        $this->assertLessThan(4, microtime(true) - $started);
    }

    public function test_a_silent_source_holds_the_probe_no_longer_than_its_deadline(): void
    {
        [$url, $resolve] = $this->silentSource();
        $probe = new SourceProbe(static fn (SourceAccess $access) => CurlSourceHttpClient::forConnectionTest($access->pin(), $resolve), [], 2);
        $started = microtime(true);

        $answer = $probe->run(new SourceAccess(url: $url, username: 'admin', password: 'secret'));

        $this->assertSame('The source site did not answer within 2 seconds.', $answer['message']);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_each_request_gives_up_well_inside_the_deadline_and_the_deadline_stays_short(): void
    {
        $this->assertLessThan(SourceProbe::DEADLINE_SECONDS, CurlSourceHttpClient::CONNECTION_TEST_TIMEOUT_SECONDS);
        $this->assertLessThanOrEqual(30, SourceProbe::DEADLINE_SECONDS);
    }

    /**
     * @return array{0: string, 1: callable(string): list<string>}
     */
    private function silentSource(): array
    {
        $address = gethostbyname(gethostname());
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || str_starts_with($address, '127.')) {
            $this->markTestSkipped('needs a non-loopback address of this host for the silent source');
        }
        $server = @stream_socket_server('tcp://'.$address.':0', $errno, $error);
        if (! is_resource($server)) {
            $this->markTestSkipped('could not listen on '.$address.': '.$error);
        }
        $this->silentSource = $server;
        OutboundUrl::setPolicyForTests(false, [self::HOST]);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        return ['http://'.self::HOST.':'.$port, static fn (): array => [$address]];
    }
}
