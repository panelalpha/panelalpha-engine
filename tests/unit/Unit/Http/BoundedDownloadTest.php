<?php

namespace Tests\Unit\Http;

use App\Lib\Http\BoundedDownload;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
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
            $response = (new BoundedDownload(1024))->fetch('http://127.0.0.1:' . $port . '/small.bin', $this->sink);
        } finally {
            $server->stop();
        }

        $this->assertSame(200, $response->status());
        $this->assertSame('archive-bytes', file_get_contents($this->sink));
    }

    private function fetch(int $port, string $path): void
    {
        (new BoundedDownload(1024))->fetch('http://127.0.0.1:' . $port . $path, $this->sink);
    }

    /**
     * @return array{0: Process, 1: int}
     */
    private function serve(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);

        $process = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->docroot]);
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $client = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
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
