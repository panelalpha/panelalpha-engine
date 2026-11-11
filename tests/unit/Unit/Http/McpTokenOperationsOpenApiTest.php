<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\McpTokenController;
use OpenApi\Annotations\Operation;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Both operations are routes the panel calls, so both are in the API documentation.
 */
class McpTokenOperationsOpenApiTest extends TestCase
{
    public function test_revoking_a_token_is_documented(): void
    {
        $operation = $this->operation('revoke', OA\Put::class);

        $this->assertSame('/mcp-tokens/{id}/revoke', $operation->path);
        $this->assertSame([200, 404], $this->statuses($operation));
        $this->assertSame('id', $operation->parameters[0]->name);
        $this->assertSame('path', $operation->parameters[0]->in);
        $this->assertTrue($operation->parameters[0]->required);
    }

    public function test_deleting_a_token_is_documented(): void
    {
        $operation = $this->operation('destroy', OA\Delete::class);

        $this->assertSame('/mcp-tokens/{id}', $operation->path);
        $this->assertSame([200, 404], $this->statuses($operation));
        $this->assertSame('id', $operation->parameters[0]->name);
        $this->assertSame('path', $operation->parameters[0]->in);
        $this->assertTrue($operation->parameters[0]->required);
    }

    /**
     * Tokens are the panel's to manage, not an assistant's.
     */
    public function test_neither_operation_is_an_mcp_tool(): void
    {
        $this->assertSame(['mcp-hide' => true], $this->operation('revoke', OA\Put::class)->x);
        $this->assertSame(['mcp-hide' => true], $this->operation('destroy', OA\Delete::class)->x);
    }

    private function operation(string $method, string $attribute): Operation
    {
        $attributes = (new ReflectionMethod(McpTokenController::class, $method))->getAttributes($attribute);

        $this->assertNotEmpty($attributes, "{$method} carries no {$attribute}");

        return $attributes[0]->newInstance();
    }

    /** @return list<int> */
    private function statuses(Operation $operation): array
    {
        $statuses = array_map(static fn (OA\Response $response): int => (int) $response->response, $operation->responses);

        sort($statuses);

        return $statuses;
    }
}
