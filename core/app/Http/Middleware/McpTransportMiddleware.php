<?php

namespace App\Http\Middleware;

use App\Mcp\McpSessions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 400 for a body that is not JSON and 404 for a session id this engine
 * never issued; the id the server hands back on initialize is what it remembers.
 */
class McpTransportMiddleware
{
    public const SESSION_HEADER = 'Mcp-Session-Id';

    public const PARSE_ERROR = -32700;

    public const SESSION_NOT_FOUND = -32000;

    public function __construct(private readonly McpSessions $sessions) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $message = json_decode((string) $request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->refuse(Response::HTTP_BAD_REQUEST, self::PARSE_ERROR, 'Parse error: Invalid JSON was received by the server.', null);
        }

        $session = (string) $request->header(self::SESSION_HEADER);
        if ($session !== '' && ! $this->sessions->knows($session)) {
            return $this->refuse(Response::HTTP_NOT_FOUND, self::SESSION_NOT_FOUND, 'Session not found.', $this->requestId($message));
        }

        $response = $next($request);

        $issued = $response->headers->get(self::SESSION_HEADER);
        if (is_string($issued) && $issued !== '') {
            $this->sessions->remember($issued);
        }

        return $response;
    }

    private function refuse(int $status, int $code, string $message, int|string|null $id): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    private function requestId(mixed $message): int|string|null
    {
        $id = is_array($message) ? ($message['id'] ?? null) : null;

        return is_int($id) || is_string($id) ? $id : null;
    }
}
