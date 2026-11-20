<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * curl asks the caller, before a request and about once a second while it
 * waits, whether to keep waiting; a false answer aborts the request. The
 * slow answer comes from a PHP built-in server on this host's private
 * address, reached through a name the operator allows.
 */
class WordpressMigrationSourceWaitTest extends TestCase
{
    use ServesSourceSite;

    private const SLOW_SECONDS = 3;

    protected function tearDown(): void
    {
        $this->stopSourceSite();
        parent::tearDown();
    }

    public function test_a_request_is_never_sent_once_the_caller_stopped_waiting(): void
    {
        $client = new CurlSourceHttpClient(null, static fn (): array => [TestNetwork::public2(20)]);
        $client->whileWaiting(static fn (): bool => false);

        $this->expectException(SourceRequestAborted::class);
        $client->request('POST', 'https://example.com/wp-json/wp-migrator/v1/remote-sync');
    }

    public function test_a_slow_answer_is_waited_for_while_the_caller_is_asked_along_the_way(): void
    {
        $client = $this->clientOnSlowServer($url);
        $asked = 0;
        $client->whileWaiting(static function () use (&$asked): bool {
            $asked++;

            return true;
        });

        $response = $client->request('GET', $url);

        $this->assertSame('scanned', $response->body);
        $this->assertGreaterThanOrEqual(self::SLOW_SECONDS, $asked);
    }

    public function test_a_slow_answer_is_aborted_as_soon_as_the_caller_stops_waiting(): void
    {
        $client = $this->clientOnSlowServer($url);
        $asked = 0;
        $client->whileWaiting(static function () use (&$asked): bool {
            return ++$asked < 3;
        });
        $started = microtime(true);

        try {
            $client->request('GET', $url);
            $this->fail('The request must be aborted');
        } catch (SourceRequestAborted) {
            $this->assertLessThan(self::SLOW_SECONDS, microtime(true) - $started);
        }
    }

    private function clientOnSlowServer(?string &$url): CurlSourceHttpClient
    {
        [$client, $base] = $this->clientOnSourceSite('<?php sleep('.self::SLOW_SECONDS.'); echo "scanned";');
        $url = $base.'/slow';

        return $client;
    }
}
