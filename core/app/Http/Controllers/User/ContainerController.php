<?php

namespace App\Http\Controllers\User;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ContainerOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ContainerController extends Controller
{
    private function getDind(string $username): Dind
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'Container management is only available for dind users'], 403));
        }

        $runtime = $user->project(app(System::class))->runtime();
        if (!$runtime instanceof Dind) {
            abort(new JsonResponse(['message' => 'Container management is only available for dind users'], 403));
        }

        return $runtime;
    }

    #[OA\Get(
        path: '/projects/{username}/containers',
        summary: 'List Docker containers of a project',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of containers', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Container'))],
            )),
        ],
    )]
    public function index(string $username): JsonResponse
    {
        $dind = $this->getDind($username);
        return new JsonResponse(['data' => $dind->getContainers()]);
    }

    #[OA\Post(
        path: '/projects/{username}/containers/action',
        summary: 'Perform a project-level Docker Compose action',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['action'],
            properties: [new OA\Property(property: 'action', type: 'string', enum: ['up', 'stop', 'restart', 'down', 'pull'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Action result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer'),
                    new OA\Property(property: 'stdout', type: 'string'),
                    new OA\Property(property: 'stderr', type: 'string'),
                ],
            )),
        ],
    )]
    public function projectAction(string $username, Request $request): JsonResponse
    {
        /**
         * @var array{
         *   action: string
         * } $validated
         */
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['up', 'stop', 'restart', 'down', 'pull'])],
        ]);

        $dind = $this->getDind($username);
        $result = $dind->projectAction($validated['action']);
        // A stop asked for is not a fault: the health sweep skips the project until it is started again.
        if ($result['exit_code'] === 0 && in_array($validated['action'], ['stop', 'down'], true)) {
            $dind->userModel()->markAppStoppedByRequest(true);
        }

        return new JsonResponse($result, $result['exit_code'] === 0 ? 200 : 500);
    }

    #[OA\Post(
        path: '/projects/{username}/containers/{service}/action',
        summary: 'Perform a service-level Docker action',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'web')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['action'],
            properties: [new OA\Property(property: 'action', type: 'string', enum: ['start', 'stop', 'restart'])],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Action result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer'),
                    new OA\Property(property: 'stdout', type: 'string'),
                ],
            )),
        ],
    )]
    public function serviceAction(string $username, string $service, Request $request): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return new JsonResponse(['message' => 'Invalid service name'], 422);
        }

        /**
         * @var array{
         *   action: string
         * } $validated
         */
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['start', 'stop', 'restart'])],
        ]);

        $dind = $this->getDind($username);
        $result = $dind->serviceAction($service, $validated['action']);
        if ($result['exit_code'] === 0 && $validated['action'] === 'stop') {
            $dind->userModel()->markAppStoppedByRequest(true);
        }

        return new JsonResponse($result, $result['exit_code'] === 0 ? 200 : 500);
    }

    /** Docker's --since/--until: an RFC 3339 time, or a duration back from now. */
    private const LOG_TIME = '/^(\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2}))?|(\d+(ms|s|m|h))+)$/';

    private const LOG_TIME_EXPECTED = 'an RFC 3339 time (2026-10-03T12:00:00Z) or a duration back from now (10m, 2h, 1h30m)';

    #[OA\Get(
        path: '/projects/{username}/containers/{service}/logs',
        description: 'The last `lines` lines (at most 5000), optionally limited to `since` / `until`.',
        summary: 'Get logs from a Docker service',
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 5000)),
            new OA\Parameter(name: 'since', description: 'Only lines written after this: ' . self::LOG_TIME_EXPECTED . '.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'until', description: 'Only lines written before this: ' . self::LOG_TIME_EXPECTED . '.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service logs', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'string')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function logs(string $username, string $service, Request $request): JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return new JsonResponse(['message' => 'Invalid service name'], 422);
        }

        $times = $this->validateLogTimes($request, ['since', 'until']);
        $lines = min((int) $request->input('lines', 200), ContainerOperations::MAX_LOG_LINES);

        $dind = $this->getDind($username);
        $logs = $dind->getServiceLogs($service, $lines, $times['since'], $times['until']);

        return new JsonResponse(['data' => $logs]);
    }

    #[OA\Get(
        path: '/projects/{username}/containers/{service}/logs/stream',
        description: 'Chunked NDJSON without Content-Length. Starts with the last `lines` lines (at most 5000, '
            . 'optionally only those after `since`), then follows the log: one `{"line":"...","ts":"..."}` '
            . 'object per line as the service writes it. While the service is quiet, a `{"type":"heartbeat"}` '
            . 'frame follows every 2 seconds without a line; skip it. Ends after 10 minutes with a final '
            . '`{"type":"finish"}` frame, or at once when the client closes the connection. MCP clients read GET '
            . '/projects/{username}/containers/{service}/logs instead.',
        summary: 'Stream a Docker service log as NDJSON',
        // A tool answers once; this never does. container_service_logs is the MCP way.
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Containers'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 5000)),
            new OA\Parameter(name: 'since', description: 'Only lines written after this: ' . self::LOG_TIME_EXPECTED . '.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'NDJSON log stream'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function streamLogs(string $username, string $service, Request $request): StreamedResponse|JsonResponse
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $service)) {
            return new JsonResponse(['message' => 'Invalid service name'], 422);
        }

        $since = $this->validateLogTimes($request, ['since'])['since'];
        $lines = min((int) $request->input('lines', 200), ContainerOperations::MAX_LOG_LINES);
        $dind = $this->getDind($username);

        ignore_user_abort(true);
        set_time_limit(0);

        return response()->stream(function () use ($dind, $service, $lines, $since) {
            // Same buffering rules as the task log stream.
            if (!app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }

            $emit = static function (array $frame): void {
                echo json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                if (!app()->runningUnitTests()) {
                    flush();
                }
            };

            $gone = false;
            // PHP learns the client left only when a write fails, so a quiet
            // service gets heartbeats; without them the follow and this worker
            // would wait for the next log line, or the full 10 minutes.
            $send = static function (array $frame) use ($emit, &$gone): void {
                $emit($frame);
                if (connection_aborted()) {
                    $gone = true;
                    // Unwinds the follow; the process is stopped with it.
                    throw new \RuntimeException('The client closed the log stream.');
                }
            };
            try {
                $dind->followServiceLogs(
                    $service,
                    $lines,
                    $since,
                    static fn (string $line, ?string $ts) => $send(['line' => $line, 'ts' => $ts]),
                    static fn () => $send(['type' => 'heartbeat']),
                );
            } catch (\RuntimeException $e) {
                if ($gone) {
                    return;
                }
                if (!$e instanceof ProcessTimedOutException) {
                    throw $e;
                }
            }
            $emit(['type' => 'finish']);
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    private function validateLogTimes(Request $request, array $fields): array
    {
        $out = [];
        $problems = [];
        foreach ($fields as $field) {
            $value = $request->query($field);
            if ($value === null || $value === '') {
                $out[$field] = null;
                continue;
            }
            if (!is_string($value) || preg_match(self::LOG_TIME, $value) !== 1) {
                $problems[] = [
                    'field' => $field,
                    'code' => $field . '_invalid',
                    'message' => "The {$field} must be " . self::LOG_TIME_EXPECTED . '.',
                    'expected' => self::LOG_TIME_EXPECTED,
                    'examples' => ['10m', '2026-10-03T12:00:00Z'],
                ];
                continue;
            }
            $out[$field] = $value;
        }
        if ($problems !== []) {
            throw ProblemException::of($problems);
        }

        return $out;
    }
}
