<?php

namespace App\Http\Middleware;

use App\Models\McpActivityLog;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records every MCP tools/call into mcp_activity_logs.
 *
 * The hand-rolled McpController used to write this row itself. laravel/mcp owns
 * the JSON-RPC dispatch now, so the log is taken here instead, from the request
 * body on the way in and the JSON-RPC envelope on the way out.
 *
 * An execute_tools call is logged as the catalogue tools it ran, one row each,
 * so the log still names the operation that touched the server.
 */
class McpActivityLogMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $body = json_decode((string)$request->getContent(), true);

        $toolName = is_array($body) && ($body['method'] ?? null) === 'tools/call'
            ? ($body['params']['name'] ?? null)
            : null;

        $response = $next($request);

        if (!is_string($toolName) || $toolName === '') {
            return $response;
        }

        $arguments = $body['params']['arguments'] ?? [];

        // A tool that returns several messages (execute_tools always does) is
        // answered as SSE, and it only runs when the body is sent -- after this
        // middleware has returned. Log from what the stream carried, once it has.
        if ($response instanceof StreamedResponse) {
            $this->logWhenStreamed($response, $request, $toolName, $arguments);

            return $response;
        }

        $reply = $response instanceof JsonResponse ? $response->getData(true) : null;
        $this->log($request, $toolName, $arguments, is_array($reply) ? $reply : null, $response->isSuccessful());

        return $response;
    }

    private function logWhenStreamed(StreamedResponse $response, Request $request, string $toolName, mixed $arguments): void
    {
        $send = $response->getCallback();

        if ($send === null) {
            return;
        }

        $response->setCallback(function () use ($send, $request, $toolName, $arguments): void {
            $sent = '';

            // Chunk size 1 flushes on every write, so this copies the stream as
            // it passes rather than holding it back.
            ob_start(function (string $chunk) use (&$sent): string {
                $sent .= $chunk;

                return $chunk;
            }, 1);

            try {
                $send();
            } finally {
                ob_end_flush();
                $this->log($request, $toolName, $arguments, $this->streamedReply($sent), true);
            }
        });
    }

    /**
     * The JSON-RPC reply in an SSE body: the last message carrying a result or
     * an error. Anything before it is a notification.
     *
     * @return array<string, mixed>|null
     */
    private function streamedReply(string $sent): ?array
    {
        $reply = null;

        foreach (preg_split('/\R/', $sent) ?: [] as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }

            $message = json_decode(substr($line, 6), true);

            if (is_array($message) && (isset($message['result']) || isset($message['error']))) {
                $reply = $message;
            }
        }

        return $reply;
    }

    /**
     * @param array<string, mixed>|null $reply
     */
    private function log(Request $request, string $toolName, mixed $arguments, ?array $reply, bool $delivered): void
    {
        $ran = $toolName === 'execute_tools' ? $this->catalogueCalls($arguments, $reply) : null;

        if ($ran === null) {
            $this->record($request, $toolName, $arguments, ...$this->outcome($reply, $delivered));

            return;
        }

        foreach ($ran as [$name, $input, $result]) {
            $this->record($request, $name, $input, ...$this->resultOutcome($result));
        }
    }

    private function record(Request $request, string $toolName, mixed $input, string $status, ?string $errorMessage): void
    {
        $token = $request->user()?->currentAccessToken();

        try {
            McpActivityLog::create([
                'token_id'      => $token instanceof PersonalAccessToken ? $token->id : null,
                'token_name'    => $token->name ?? 'unknown',
                'tool_name'     => $toolName,
                // Credentials are stripped by McpActivityLog's own mutator, so
                // no write path can forget to.
                'input'         => is_array($input) && $input !== [] ? $input : null,
                'status'        => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            // The tool has already run by the time we get here. Failing the
            // request now would report a completed -- possibly destructive --
            // operation as a 500 and invite the client to retry it.
            report($e);
        }
    }

    /**
     * A tool failure surfaces as result.isError; a dispatch failure (unknown
     * tool, bad params) surfaces as a JSON-RPC error object.
     *
     * @param array<string, mixed>|null $reply
     * @return array{0: string, 1: string|null}
     */
    private function outcome(?array $reply, bool $delivered): array
    {
        if ($reply === null) {
            return [$delivered ? 'success' : 'error', null];
        }

        if (isset($reply['error'])) {
            return ['error', (string)($reply['error']['message'] ?? 'Unknown error')];
        }

        return $this->resultOutcome(is_array($reply['result'] ?? null) ? $reply['result'] : []);
    }

    /**
     * @param array<string, mixed> $result
     * @return array{0: string, 1: string|null}
     */
    private function resultOutcome(array $result): array
    {
        if (($result['isError'] ?? false) === true) {
            return ['error', $this->firstText($result['content'] ?? [])];
        }

        return ['success', null];
    }

    /**
     * The calls execute_tools ran, each with its own result, or null when it
     * ran none (bad arguments, output limit) and the call itself is what gets
     * logged. Calls after the first failure never ran and are left out.
     *
     * @param array<string, mixed>|null $reply
     * @return array<int, array{0: string, 1: mixed, 2: array<string, mixed>}>|null
     */
    private function catalogueCalls(mixed $arguments, ?array $reply): ?array
    {
        $text = $this->firstText($reply['result']['content'] ?? []);
        $summary = is_string($text) ? json_decode($text, true) : null;
        $results = is_array($summary) ? ($summary['results'] ?? null) : null;
        $calls = is_array($arguments) ? ($arguments['calls'] ?? null) : null;

        if (!is_array($results) || $results === [] || !is_array($calls)) {
            return null;
        }

        $ran = [];

        foreach (array_values($results) as $i => $result) {
            if (!is_array($result) || !is_string($result['name'] ?? null)) {
                return null;
            }

            $ran[] = [$result['name'], $calls[$i]['arguments'] ?? [], $result];
        }

        return $ran;
    }

    /**
     * @param array<int, mixed> $content
     */
    private function firstText(mixed $content): ?string
    {
        foreach (is_array($content) ? $content : [] as $item) {
            if (is_array($item) && isset($item['text']) && is_string($item['text'])) {
                return $item['text'];
            }
        }

        return null;
    }
}
