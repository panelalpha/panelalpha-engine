<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * A tool's request to the API is made on the address the MCP call came in on, so the links and `meta.path` a
 * listing answers with are the ones REST gives, not http://localhost.
 */
class ApiToolOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/test-mcp/page', fn (HttpRequest $r) => response()->json([
            'url' => $r->url(),
            'page' => new LengthAwarePaginator([], 30, 10, 1, ['path' => Paginator::resolveCurrentPath()]),
        ]));
    }

    public function test_the_links_name_the_address_the_mcp_call_came_in_on(): void
    {
        $this->app->instance('request', HttpRequest::create('https://engine.example.test:2011/mcp', 'POST'));

        $data = $this->toolResult()['data'];

        $this->assertSame('https://engine.example.test:2011/api/test-mcp/page', $data['url']);
        $this->assertSame('https://engine.example.test:2011/api/test-mcp/page', $data['page']['path']);
        $this->assertSame('https://engine.example.test:2011/api/test-mcp/page?page=2', $data['page']['next_page_url']);
    }

    public function test_a_call_on_the_default_port_names_no_port(): void
    {
        $this->app->instance('request', HttpRequest::create('http://engine.example.test/mcp', 'POST'));

        $this->assertSame('http://engine.example.test/api/test-mcp/page', $this->toolResult()['data']['url']);
    }

    /** @return array<string, mixed> */
    private function toolResult(): array
    {
        $tool = new class extends ApiTool
        {
            protected function method(): string
            {
                return 'GET';
            }

            protected function path(): string
            {
                return '/test-mcp/page';
            }
        };

        return json_decode((string) $tool->handle(new Request([]))->content(), true);
    }
}
