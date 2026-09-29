<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use App\Mcp\Tools\Api\DownloadWindow;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

/**
 * Binary and streamed responses return false from getContent(), so a
 * download over MCP used to come back as 200 {data: null}.
 */
class ApiToolFileResponseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'mcp_dl_') . '.txt';

        Route::get('/api/test-mcp/download', fn () => response()->download($this->file));
        Route::get('/api/test-mcp/stream', fn () => response()->stream(fn () => print('x')));
        Route::get('/api/test-mcp/huge', fn () => response()->json(['blob' => str_repeat('a', ApiTool::MAX_RESULT_BYTES)]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_a_binary_download_comes_back_as_base64_with_its_metadata(): void
    {
        file_put_contents($this->file, "hello\0world");

        $response = $this->tool('/test-mcp/download')->handle(new Request());

        $this->assertFalse($response->isError());
        $payload = $this->payload($response);
        $this->assertSame(200, $payload['status']);
        $this->assertSame(basename($this->file), $payload['data']['filename']);
        $this->assertSame('text/plain', $payload['data']['mime_type']);
        $this->assertSame(11, $payload['data']['size']);
        $this->assertSame('base64', $payload['data']['encoding']);
        $this->assertSame("hello\0world", base64_decode($payload['data']['content']));
        $this->assertFalse($payload['data']['more']);
        $this->assertNull($payload['data']['next_offset']);
    }

    public function test_text_comes_back_as_text(): void
    {
        file_put_contents($this->file, "GET / 200\n");

        $data = $this->payload($this->tool('/test-mcp/download')->handle(new Request()))['data'];

        $this->assertSame('utf-8', $data['encoding']);
        $this->assertSame("GET / 200\n", $data['content']);
    }

    public function test_a_big_file_is_read_one_window_at_a_time_to_the_last_byte(): void
    {
        // Multibyte characters straddle the window edges, so text windows have
        // to back off to a whole character and still lose nothing.
        $contents = str_repeat("zażółć gęślą jaźń\n", 8000);
        file_put_contents($this->file, $contents);

        $read = '';
        $offset = 0;
        $windows = 0;
        do {
            $data = $this->payload($this->tool('/test-mcp/download')->handle(new Request(['offset' => $offset])))['data'];
            $this->assertSame('utf-8', $data['encoding']);
            $this->assertLessThanOrEqual(DownloadWindow::MAX_BYTES, $data['length']);
            $this->assertSame(strlen($contents), $data['size']);
            $read .= $data['content'];
            $offset = $data['next_offset'] ?? $offset;
            $windows++;
        } while ($data['more']);

        $this->assertSame($contents, $read);
        $this->assertGreaterThan(1, $windows);
    }

    public function test_length_is_capped_by_validation(): void
    {
        file_put_contents($this->file, 'x');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->tool('/test-mcp/download')->handle(new Request(['length' => DownloadWindow::MAX_BYTES + 1]));
    }

    public function test_a_result_over_the_limit_says_what_to_narrow_instead_of_being_sent(): void
    {
        $response = $this->tool('/test-mcp/huge')->handle(new Request());

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('over the 256 KB a tool returns', (string)$response->content());
    }

    public function test_a_stream_is_refused_rather_than_read_as_null(): void
    {
        $response = $this->tool('/test-mcp/stream')->handle(new Request());

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('streams its response', (string)$response->content());
    }

    private function tool(string $path): ApiTool
    {
        return new class ($path) extends ApiTool {
            public function __construct(private string $apiPath)
            {
            }

            protected function method(): string
            {
                return 'GET';
            }

            protected function path(): string
            {
                return $this->apiPath;
            }

            protected function returnsFile(): bool
            {
                return true;
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string)$response->content(), true);
    }
}
