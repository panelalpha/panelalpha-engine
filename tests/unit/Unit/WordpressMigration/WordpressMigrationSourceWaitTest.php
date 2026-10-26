<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\Support\JobBudget;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceRequestAborted;
use PHPUnit\Framework\TestCase;

/**
 * curl asks the caller, before a request and about once a second while it
 * waits, whether to keep waiting; a false answer aborts the request. The
 * slow answer comes from a PHP built-in server on a private address, since
 * the fetch guard refuses loopback.
 */
class WordpressMigrationSourceWaitTest extends TestCase
{
    private const SLOW_SECONDS = 3;

    /** @var array{0: resource, 1: string}|null */
    private ?array $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            [$process, $dir] = $this->server;
            proc_terminate($process);
            proc_close($process);
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
            $this->server = null;
        }
        parent::tearDown();
    }

    public function test_a_request_is_never_sent_once_the_caller_stopped_waiting(): void
    {
        $client = new CurlSourceHttpClient(null, static fn (): array => ['198.51.100.20']);
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

    public function test_a_source_call_may_outlast_the_stale_window_but_not_the_job(): void
    {
        $this->assertSame(15, WordpressMigration::STALE_MINUTES);
        $this->assertGreaterThan(WordpressMigration::STALE_MINUTES * 60, CurlSourceHttpClient::REQUEST_TIMEOUT_SECONDS);
        $this->assertLessThan(JobBudget::SECONDS, CurlSourceHttpClient::REQUEST_TIMEOUT_SECONDS);
    }

    private function clientOnSlowServer(?string &$url): CurlSourceHttpClient
    {
        $address = gethostbyname(gethostname());
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || str_starts_with($address, '127.')) {
            $this->markTestSkipped('needs a non-loopback address of this host for the built-in server');
        }

        $url = 'http://wp.example:' . $this->serve($address) . '/slow';

        return new CurlSourceHttpClient(null, static fn (): array => [$address]);
    }

    private function serve(string $address): int
    {
        $dir = sys_get_temp_dir() . '/pa-source-wait-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/router.php', '<?php sleep(' . self::SLOW_SECONDS . '); echo "scanned";');

        $port = random_int(23000, 23999);
        $process = proc_open(
            sprintf('exec php -S %s:%d %s/router.php', $address, $port, $dir),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('could not start a PHP built-in server');
        }
        $this->server = [$process, $dir];

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen($address, $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return $port;
            }
            usleep(100_000);
        }

        $this->markTestSkipped('the built-in server did not come up');
    }
}
