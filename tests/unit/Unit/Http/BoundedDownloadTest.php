<?php

namespace Tests\Unit\Http;

use App\Lib\Http\BoundedDownload;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * A URL download is refused while it is still arriving, not once the whole
 * body has landed on core's disk. The sink this replaced wrote every byte and
 * compared filesize() only after the transfer had finished.
 */
class BoundedDownloadTest extends TestCase
{
    private string $docroot;

    private string $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docroot = sys_get_temp_dir() . '/pa-bounded-' . bin2hex(random_bytes(4));
        mkdir($this->docroot, 0777, true);
        $this->sink = sys_get_temp_dir() . '/pa-bounded-sink-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->sink);
        exec('rm -rf ' . escapeshellarg($this->docroot));
        parent::tearDown();
    }

    public function test_an_oversized_content_length_never_reaches_the_file(): void
    {
        file_put_contents($this->docroot . '/big.bin', str_repeat('x', 64 * 1024));
        [$server, $port] = $this->serve();

        try {
            $this->fetch($port, '/big.bin');
            $this->fail('an oversized Content-Length was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('MB limit', $e->getMessage());
        } finally {
            $server->stop();
        }

        $this->assertLessThan(64 * 1024, (int) @filesize($this->sink));
    }

    public function test_a_chunked_body_is_stopped_once_it_passes_the_cap(): void
    {
        file_put_contents(
            $this->docroot . '/stream.php',
            '<?php for ($i = 0; $i < 200; $i++) { echo str_repeat("x", 1024); @ob_flush(); flush(); }'
        );
        [$server, $port] = $this->serve();

        try {
            $this->fetch($port, '/stream.php');
            $this->fail('a chunked body past the cap was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('MB limit', $e->getMessage());
        } finally {
            $server->stop();
        }

        $this->assertLessThan(200 * 1024, (int) @filesize($this->sink), 'the transfer ran to the end');
    }

    public function test_a_body_within_the_cap_is_written_whole(): void
    {
        file_put_contents($this->docroot . '/small.bin', 'archive-bytes');
        [$server, $port] = $this->serve();

        try {
            $response = (new BoundedDownload(1024))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/small.bin', $this->sink);
        } finally {
            $server->stop();
        }

        $this->assertSame(200, $response->status());
        $this->assertSame('archive-bytes', file_get_contents($this->sink));
    }

    public function test_a_redirect_is_followed_only_when_asked(): void
    {
        file_put_contents($this->docroot . '/small.bin', 'archive-bytes');
        file_put_contents($this->docroot . '/moved.php', '<?php header("Location: /small.bin", true, 302);');
        [$server, $port] = $this->serve();

        try {
            $unfollowed = (new BoundedDownload(1024))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/moved.php', $this->sink);
            $followed = (new BoundedDownload(1024, followRedirects: true))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/moved.php', $this->sink);
        } finally {
            $server->stop();
        }

        $this->assertSame(302, $unfollowed->status());
        $this->assertSame(200, $followed->status());
        $this->assertSame('archive-bytes', file_get_contents($this->sink));
    }

    public function test_the_cap_still_holds_behind_a_followed_redirect(): void
    {
        file_put_contents($this->docroot . '/big.bin', str_repeat('x', 64 * 1024));
        file_put_contents($this->docroot . '/moved.php', '<?php header("Location: /big.bin", true, 302);');
        [$server, $port] = $this->serve();

        try {
            (new BoundedDownload(1024, followRedirects: true))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/moved.php', $this->sink);
            $this->fail('an oversized body behind a redirect was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('MB limit', $e->getMessage());
        } finally {
            $server->stop();
        }

        $this->assertLessThan(64 * 1024, (int) @filesize($this->sink));
    }

    public function test_a_download_gives_up_after_its_timeout(): void
    {
        file_put_contents($this->docroot . '/stalled.php', '<?php sleep(5); echo "late";');
        [$server, $port] = $this->serve();
        $started = microtime(true);

        try {
            (new BoundedDownload(1024, timeoutSeconds: 1))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . '/stalled.php', $this->sink);
            $this->fail('a stalled download was waited for');
        } catch (ConnectionException) {
            $this->assertLessThan(4, microtime(true) - $started);
        } finally {
            $server->stop();
        }
    }

    private function fetch(int $port, string $path): void
    {
        (new BoundedDownload(1024))->fetch('http://' . TestNetwork::LOOPBACK . ':' . $port . $path, $this->sink);
    }

    /**
     * @return array{0: Process, 1: int}
     */
    private function serve(): array
    {
        $socket = stream_socket_server('tcp://' . TestNetwork::LOOPBACK . ':0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $process = new Process([PHP_BINARY, '-S', TestNetwork::LOOPBACK . ':' . $port, '-t', $this->docroot]);
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $client = @fsockopen(TestNetwork::LOOPBACK, $port, $errno, $errstr, 0.1);
            if (is_resource($client)) {
                fclose($client);

                return [$process, $port];
            }
            usleep(50000);
        }

        $process->stop();
        $this->fail('Local server did not start: ' . $process->getErrorOutput());
    }
}
