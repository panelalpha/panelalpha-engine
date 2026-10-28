<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * A result over the size a tool returns names what to ask for instead: a tool
 * that pages by `limit` is told to send a smaller one.
 */
class ApiToolOversizeHintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/api/test-mcp/huge-page', fn () => response()->json(['rows' => str_repeat('a', ApiTool::MAX_RESULT_BYTES * 2)]));
    }

    public function test_a_page_with_no_limit_sent_is_asked_for_a_smaller_one(): void
    {
        $message = (string) $this->pagedTool()->handle(new Request())->content();

        $this->assertStringContainsString('over the 256 KB a tool returns. Call again with a smaller `limit`.', $message);
    }

    public function test_the_suggested_limit_is_half_of_what_would_have_fit(): void
    {
        $message = (string) $this->pagedTool()->handle(new Request(['limit' => 100]))->content();

        $this->assertStringContainsString('Call again with `limit` 24 or less (this call asked for 100).', $message);
    }

    public function test_a_page_of_one_row_cannot_get_smaller_so_the_general_advice_stands(): void
    {
        $message = (string) $this->pagedTool()->handle(new Request(['limit' => 1]))->content();

        $this->assertStringContainsString('Ask for less: use the paging or filter arguments', $message);
        $this->assertStringNotContainsString('Call again', $message);
    }

    private function pagedTool(): ApiTool
    {
        return new class extends ApiTool {
            protected function method(): string
            {
                return 'GET';
            }

            protected function path(): string
            {
                return '/test-mcp/huge-page';
            }

            protected function queryParams(): array
            {
                return ['limit'];
            }
        };
    }
}
