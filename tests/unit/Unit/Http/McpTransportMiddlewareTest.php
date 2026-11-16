<?php

namespace Tests\Unit\Http;

use App\Auth\TokenAbilities;
use App\Http\Middleware\McpActivityLogMiddleware;
use App\Http\Middleware\McpTransportMiddleware;
use App\Mcp\McpSessions;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Two statuses the streamable-HTTP spec asks for and laravel/mcp never sets:
 * 400 for a body that is not JSON, 404 for a session id never issued.
 */
class McpTransportMiddlewareTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_body_that_is_not_json_is_a_400(): void
    {
        [$response, $served] = $this->send('{"jsonrpc": 2.0, "id": 1, "method":');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(McpTransportMiddleware::PARSE_ERROR, $response->getData(true)['error']['code']);
        $this->assertFalse($served, 'a body that cannot be parsed must not reach the server');
    }

    public function test_a_request_without_a_session_id_is_served(): void
    {
        [$response, $served] = $this->send($this->message('tools/list', 1));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($served);
    }

    public function test_a_session_id_this_engine_never_issued_is_a_404(): void
    {
        [$response, $served] = $this->send($this->message('tools/list', 7), [McpTransportMiddleware::SESSION_HEADER => 'never-issued']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(McpTransportMiddleware::SESSION_NOT_FOUND, $response->getData(true)['error']['code']);
        $this->assertSame(7, $response->getData(true)['id']);
        $this->assertFalse($served, 'an unknown session must not reach the server');
    }

    /** The id the server hands back on initialize is the one a later request may carry. */
    public function test_the_id_the_server_hands_back_is_served_afterwards(): void
    {
        $this->send($this->message('initialize', 1), issued: 'a45f1a7e-0e3a-4b4c-8ca6-5f0f26a37f8e');

        [$response, $served] = $this->send(
            $this->message('tools/list', 2),
            [McpTransportMiddleware::SESSION_HEADER => 'a45f1a7e-0e3a-4b4c-8ca6-5f0f26a37f8e']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($served);
    }

    public function test_an_empty_body_is_a_400(): void
    {
        [$response] = $this->send('');

        $this->assertSame(400, $response->getStatusCode());
    }

    /** Wired ahead of the server and ahead of the activity log, so neither sees a refused request. */
    public function test_the_mcp_route_runs_it_first(): void
    {
        $route = collect(RouteFacade::getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === 'mcp' && $route->methods() === ['POST']);

        $this->assertNotNull($route, 'POST /mcp is not registered');
        $this->assertSame(
            [McpTransportMiddleware::class, McpActivityLogMiddleware::class],
            array_slice($route->middleware(), -2)
        );
    }

    public function test_the_route_answers_400_for_a_body_that_is_not_json(): void
    {
        $this->rpcPost('/mcp', '{"jsonrpc": 2.0,')
            ->assertStatus(400)
            ->assertJsonPath('error.code', McpTransportMiddleware::PARSE_ERROR);
    }

    public function test_the_route_answers_404_for_a_session_id_it_never_issued(): void
    {
        $this->rpcPost('/mcp', $this->message('tools/list', 9), ['Mcp-Session-Id' => 'never-issued'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', McpTransportMiddleware::SESSION_NOT_FOUND)
            ->assertJsonPath('id', 9);
    }

    /** @param array<string, string> $headers */
    private function rpcPost(string $uri, string $body, array $headers = []): TestResponse
    {
        $token = Admin::rootAccount()->createToken('tests', TokenAbilities::build(api: false, mcp: true))->plainTextToken;

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ] + array_combine(
            array_map(fn (string $name): string => 'HTTP_'.str_replace('-', '_', strtoupper($name)), array_keys($headers)),
            array_values($headers)
        ), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: Response, 1: bool}
     */
    private function send(string $body, array $headers = [], ?string $issued = null): array
    {
        $request = Request::create('/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $reached = false;
        $next = function () use (&$reached, $issued): Response {
            $reached = true;
            $response = new Response('{"jsonrpc":"2.0","id":"1","result":{}}', 200, ['Content-Type' => 'application/json']);
            if ($issued !== null) {
                $response->headers->set(McpTransportMiddleware::SESSION_HEADER, $issued);
            }

            return $response;
        };

        $response = (new McpTransportMiddleware(new McpSessions))->handle($request, $next);

        return [$response, $reached];
    }

    private function message(string $method, int $id): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => []], JSON_THROW_ON_ERROR);
    }
}
