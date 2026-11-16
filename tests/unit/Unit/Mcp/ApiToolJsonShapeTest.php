<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Tools\Api\ApiTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * A tool relays the API's JSON as it is: an empty object stays `{}` and an
 * empty array stays `[]`.
 */
class ApiToolJsonShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/api/test-mcp/shape', fn (HttpRequest $r) => response()->json([
            'build_args' => new \stdClass,
            'files' => [],
            'nested' => ['inner' => new \stdClass, 'list' => []],
        ]));
        Route::post('/api/test-mcp/refused', fn (HttpRequest $r) => response()->json([
            'message' => 'The given data was invalid.',
            'errors' => ['username' => ['The username has already been taken.']],
            'problems' => [['field' => 'username', 'code' => 'username_invalid']],
        ], 422));
    }

    public function test_an_empty_object_is_not_re_encoded_as_an_array(): void
    {
        $body = (string) $this->tool('/test-mcp/shape')->handle(new Request([]))->content();

        $this->assertStringContainsString('"build_args":{}', $body);
        $this->assertStringContainsString('"files":[]', $body);
        $this->assertStringContainsString('"inner":{}', $body);
        $this->assertStringContainsString('"list":[]', $body);
    }

    public function test_an_error_body_still_names_fields_as_the_tool_does(): void
    {
        $body = (string) $this->tool('/test-mcp/refused')->handle(new Request([]))->content();

        $this->assertStringContainsString('"name"', $body);
        $this->assertStringContainsString('"field":"name"', $body);
        $this->assertStringContainsString('"code":"name_invalid"', $body);
    }

    private function tool(string $path): ApiTool
    {
        return new class($path) extends ApiTool
        {
            public function __construct(private string $apiPath) {}

            protected function method(): string
            {
                return 'POST';
            }

            protected function path(): string
            {
                return $this->apiPath;
            }

            protected function argumentNames(): array
            {
                return ['name' => 'username'];
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
    }
}
