<?php

namespace Tests\Unit\Mcp;

use App\Http\Requests\FtpAccountUpdateRequest;
use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

/**
 * A tool's JSON body has to read the same as one sent over HTTP. It used to
 * reach only the json bag, so `$request->get()` saw null and ftp_account_update
 * stored quota 0 for any quota it was given.
 */
class ApiToolJsonBodyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::put('/api/test-mcp/echo', fn (HttpRequest $r) => response()->json([
            'get' => $r->get('quota'),
            'input' => $r->input('quota'),
        ]));
        Route::put('/api/test-mcp/ftp-quota', fn (FtpAccountUpdateRequest $r) => response()->json([
            'provided' => $r->quotaProvided(),
            'quota' => $r->getQuota(),
        ]));
    }

    public function test_the_body_reads_through_get_as_well_as_input(): void
    {
        $data = $this->payload($this->tool('/test-mcp/echo')->handle(new Request(['quota' => 50])))['data'];

        $this->assertSame(['get' => 50, 'input' => 50], $data);
    }

    public function test_an_ftp_quota_sent_by_a_tool_is_the_quota_the_request_reports(): void
    {
        $data = $this->payload($this->tool('/test-mcp/ftp-quota')->handle(new Request(['quota' => 50])))['data'];
        $this->assertSame(['provided' => true, 'quota' => 50], $data);

        $data = $this->payload($this->tool('/test-mcp/ftp-quota')->handle(new Request(['unlimited_quota' => true])))['data'];
        $this->assertSame(['provided' => true, 'quota' => null], $data);
    }

    private function tool(string $path): ApiTool
    {
        return new class ($path) extends ApiTool {
            public function __construct(private string $apiPath)
            {
            }

            protected function method(): string
            {
                return 'PUT';
            }

            protected function path(): string
            {
                return $this->apiPath;
            }

            protected function bodyParams(): array
            {
                return ['quota', 'unlimited_quota'];
            }
        };
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string)$response->content(), true);
    }
}
