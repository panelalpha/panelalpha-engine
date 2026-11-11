<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\McpAuthMiddleware;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The /mcp door refuses under the same `message` key as every REST refusal. */
class McpAuthMiddlewareTest extends TestCase
{
    public function test_a_request_without_a_token_is_refused_under_message(): void
    {
        $this->assertRefusal($this->handle(null), 'Unauthorized');
    }

    public function test_a_token_not_issued_for_an_assistant_is_refused_under_message(): void
    {
        $token = $this->token(TokenAbilities::build(api: true, mcp: false));

        $response = $this->handle($token);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(McpAuthMiddleware::NOT_FOR_MCP, $response->getData(true)['code']);
        $this->assertSame('This token was not issued for an assistant; it takes a token minted for one.', $response->getData(true)['message']);
        $this->assertSame(
            [['type' => 'cli', 'action' => 'mint_mcp_token', 'command' => 'pae mcp:token:create <name>']],
            $response->getData(true)['problems'][0]['hints'],
        );
    }

    public function test_a_revoked_token_is_refused_under_message(): void
    {
        $token = $this->token(TokenAbilities::build(api: false, mcp: true));
        $token->revoked_at = now();

        $this->assertRefusal($this->handle($token), 'Token has been revoked');
    }

    public function test_an_assistant_token_passes(): void
    {
        $response = $this->handle($this->token(TokenAbilities::build(api: false, mcp: true)));

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @param list<string> $abilities */
    private function token(array $abilities): PersonalAccessToken
    {
        $token = new PersonalAccessToken;
        $token->abilities = $abilities;

        return $token;
    }

    private function handle(?PersonalAccessToken $token): JsonResponse
    {
        $request = Request::create('/mcp', 'POST');
        $request->setUserResolver(fn () => $token === null ? null : new class($token)
        {
            public function __construct(private PersonalAccessToken $token) {}

            public function currentAccessToken(): PersonalAccessToken
            {
                return $this->token;
            }
        });

        return (new McpAuthMiddleware)->handle($request, fn () => new JsonResponse(['ok' => true]));
    }

    private function assertRefusal(JsonResponse $response, string $message): void
    {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => $message], $response->getData(true));
    }
}
