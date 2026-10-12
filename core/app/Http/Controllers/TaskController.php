<?php

namespace App\Http\Controllers;

use App\Auth\TokenAbilities;
use App\Http\Controllers\Concerns\StreamsNdjson;
use App\Http\Requests\TaskListRequest;
use App\Http\Resources\TaskLogCollection;
use App\Http\Resources\TaskLogResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TaskSummaryCollection;
use App\System;
use App\Lib\Task\LogStreamLimiter;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    use StreamsNdjson;

    /** Silence after which the log stream checks that its reader is still there. */
    public const STREAM_HEARTBEAT_SECONDS = 2;

    private float $lastFrameAt = 0.0;

    private function taskOr404(int $id): Task
    {
        $task = Task::find($id);
        if (!$task) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        return $task;
    }

    /** The `since` filter, or a 422. An array counts as no filter. */
    private function since(Request $request): int|string|null
    {
        $since = $request->query('since');
        if (is_array($since)) {
            return null;
        }

        if (!Task::isSince($since)) {
            abort(new JsonResponse(['message' => Task::SINCE_EXPECTED], 422));
        }

        return $since;
    }

    #[OA\Get(
        path: '/tasks',
        summary: 'List tasks newest first, filtered by project, status, action or time',
        description: 'Covers every queued job on the engine, not only deploys. `scope=mine` (the default) shows the '
            . 'tasks the calling token started plus engine-originated work (deploy hooks, schedules, CLI) on projects '
            . 'it may reach; `scope=all` shows every task and is refused with 403 for a token limited to part of the '
            . 'API. `status` takes a comma list of queued, running, completed, failed, cancelled; `active` is '
            . 'shorthand for queued, running. `action` matches details.action, for example deploy, rebuild, staging, '
            . 'push, backup, restore or attach_source.',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'scope', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['mine', 'all'], default: 'mine')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200, minimum: 1)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of task summaries', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskSummary')),
                    new OA\Property(property: 'meta', type: 'object'),
                    new OA\Property(property: 'links', type: 'object'),
                ],
            )),
            new OA\Response(response: 403, description: 'scope=all was asked for with a limited token', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(TaskListRequest $request): TaskSummaryCollection
    {
        $abilities = TokenAbilities::forCurrentRequest();
        $this->refuseScopeAllUnlessUnrestricted($request, $abilities);

        $query = Task::query()->orderByDesc('id');
        $this->applyFilters($query, $request);
        $this->applyScope($query, $request, $abilities);

        return new TaskSummaryCollection($query->paginate($request->perPage()));
    }

    private function refuseScopeAllUnlessUnrestricted(TaskListRequest $request, TokenAbilities $abilities): void
    {
        if ($request->scope() !== TaskListRequest::SCOPE_ALL || $abilities->isUnrestricted()) {
            return;
        }

        abort(new JsonResponse(['message' => 'This token is limited to part of the API, so it may not read every task.'], 403));
    }

    /**
     * @param Builder<Task> $query
     */
    private function applyFilters(Builder $query, TaskListRequest $request): void
    {
        $filters = $request->validated();

        if (isset($filters['username'])) {
            $query->where('username', $filters['username']);
        }
        if (isset($filters['action'])) {
            $query->where('details->action', $filters['action']);
        }
        if ($request->statuses() !== []) {
            $query->whereIn('status', $request->statuses());
        }
        if ($request->sinceAt() !== null) {
            $query->where('updated_at', '>=', $request->sinceAt());
        }
    }

    /**
     * @param Builder<Task> $query
     */
    private function applyScope(Builder $query, TaskListRequest $request, TokenAbilities $abilities): void
    {
        if ($request->scope() === TaskListRequest::SCOPE_ALL) {
            $this->applyProjectReach($query, $abilities);

            return;
        }

        $tokenId = Task::currentTokenId();
        $query->where(function (Builder $caller) use ($tokenId, $abilities): void {
            $caller->where('api_token_id', $tokenId)
                ->orWhere(function (Builder $engine) use ($abilities): void {
                    $engine->whereNull('api_token_id');
                    $this->applyProjectReach($engine, $abilities);
                });
        });
    }

    /**
     * @param Builder<Task> $query
     */
    private function applyProjectReach(Builder $query, TokenAbilities $abilities): void
    {
        $projects = $abilities->projects();
        if ($projects !== null) {
            $query->whereIn('username', $projects);
        }
    }

    #[OA\Get(
        path: '/tasks/{id}',
        summary: 'Poll a task status and new log lines after a cursor',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'after_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Task status and log page', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Task not found', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function show(int $id): TaskResource|JsonResponse
    {
        $task = $this->taskOr404($id);

        return new TaskResource($task);
    }

    #[OA\Get(
        path: '/tasks/{id}/logs',
        description: 'Pass `since` as unix seconds or an ISO datetime to keep only rows '
            . 'with created_at greater than that instant. Pass `after_id` to skip rows at '
            . 'or below that id (needed when many lines share the same second). Both filters '
            . 'AND together. Prefer this over the logs embedded in GET /tasks/{id} when '
            . 'polling by time.',
        summary: 'Page of task log lines after a timestamp and/or id cursor',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'since',
                in: 'query',
                required: false,
                schema: new OA\Schema(oneOf: [
                    new OA\Schema(type: 'integer', description: 'Unix timestamp (seconds)'),
                    new OA\Schema(type: 'string', description: 'ISO datetime'),
                ]),
            ),
            new OA\Parameter(name: 'after_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Log page', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'meta', type: 'object'),
                ],
            )),
            new OA\Response(response: 404, description: 'Task not found', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function logs(int $id, Request $request): TaskLogCollection|JsonResponse
    {
        $task = $this->taskOr404($id);

        $afterId = max(0, (int) $request->query('after_id', 0));
        $since = $this->since($request);
        $page = $task->logsPage($afterId, $since);
        $nextAfterId = $page->isEmpty() ? $afterId : (int) $page->last()->id;

        return new TaskLogCollection($page, $task->status, $nextAfterId);
    }

    #[OA\Get(
        path: '/tasks/{id}/logs/stream',
        description: 'Chunked NDJSON without Content-Length. Each log line is one JSON object; '
            . 'a final `{"type":"finish","status":"..."}` frame closes the stream when the task '
            . 'is terminal and no further lines remain. While the task logs nothing, a `{"type":"heartbeat"}` '
            . 'frame follows every 2 seconds without a line; skip it. Optional `since` / `after_id` skip the '
            . 'backlog. Long-lived: MCP clients should poll GET /tasks/{id}/logs instead.',
        summary: 'Stream task log lines as NDJSON until the task finishes',
        // A tool answers once; this never does. task_log_list is the MCP way.
        x: ['mcp-hide' => true],
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'since',
                in: 'query',
                required: false,
                schema: new OA\Schema(oneOf: [
                    new OA\Schema(type: 'integer'),
                    new OA\Schema(type: 'string'),
                ]),
            ),
            new OA\Parameter(name: 'after_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'NDJSON log stream'),
            new OA\Response(response: 404, description: 'Task not found', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 503, description: 'All log stream slots are in use', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function streamLogs(int $id, Request $request): StreamedResponse|JsonResponse
    {
        $task = $this->taskOr404($id);

        $slot = LogStreamLimiter::acquire();
        if ($slot === null) {
            return $this->tooManyStreams();
        }

        $afterId = max(0, (int) $request->query('after_id', 0));
        $since = $this->since($request);

        return $this->ndjsonStream($task, $afterId, $since, $slot);
    }

    /** A stream holds a worker for the task's whole life, so streams are capped and log followers cannot take every worker. */
    private function tooManyStreams(): JsonResponse
    {
        return new JsonResponse(['message' => LogStreamLimiter::MESSAGE], 503, ['Retry-After' => 5]);
    }

    /**
     * The streamed response, which frees its limiter slot when it ends. Anything that can refuse the
     * request must run before this: a throw inside the callback comes after the 200 headers.
     *
     * @param resource $slot
     */
    private function ndjsonStream(Task $task, int $afterId, int|string|null $since, $slot): StreamedResponse
    {
        return self::ndjsonResponse(function () use ($task, $afterId, $since, $slot) {
            try {
                $this->streamTaskLogs($task, $afterId, $since);
            } finally {
                LogStreamLimiter::release($slot);
            }
        });
    }

    /**
     * The NDJSON frames a log stream emits, until the task is terminal and no
     * further lines remain. Once a line gives an id cursor the `since` filter is
     * dropped, so lines sharing that second are not fetched again.
     */
    private function streamTaskLogs(Task $task, int $afterId, int|string|null $since): void
    {
        $this->dropOutputBuffers();
        $this->lastFrameAt = microtime(true);
        $cursor = $afterId;
        $sinceCursor = $since;

        while (!connection_aborted()) {
            $task->refresh();
            $page = $task->logsPage($cursor, $sinceCursor);

            $lastId = $this->emitLogLines($page);
            if ($lastId !== null) {
                $cursor = $lastId;
                $sinceCursor = null;
            }

            if ($page->isEmpty()) {
                if ($task->isTerminal()) {
                    $this->emitFrame([
                        'type' => 'finish',
                        'status' => $task->status,
                    ]);

                    return;
                }
                $this->waitQuietly();
            }
        }
    }

    /**
     * @param iterable<\App\Models\TaskLog> $page
     * @return ?int the id of the last line emitted, null when there was none
     */
    private function emitLogLines(iterable $page): ?int
    {
        $lastId = null;
        foreach ($page as $row) {
            $parsed = TaskLogResource::parsePayload($row->log);
            $this->emitFrame([
                'id' => $row->id,
                'log' => $parsed['msg'],
                'level' => $parsed['level'],
                'stage' => $parsed['stage'],
                'created_at' => optional($row->created_at)?->toIso8601String(),
            ]);
            $lastId = (int) $row->id;
        }

        return $lastId;
    }

    /**
     * PHP learns the client left only when a write fails, so a quiet task gets heartbeats;
     * otherwise this worker stays busy until the task logs again.
     */
    private function waitQuietly(): void
    {
        if (microtime(true) - $this->lastFrameAt >= self::STREAM_HEARTBEAT_SECONDS) {
            $this->emitFrame(['type' => 'heartbeat']);
        }
        usleep(200000);
    }

    /** @param array<string, mixed> $frame */
    private function emitFrame(array $frame): void
    {
        echo json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (!app()->runningUnitTests()) {
            flush();
        }
        $this->lastFrameAt = microtime(true);
    }

    #[OA\Post(
        path: '/tasks/{id}/cancel',
        summary: 'Cancel a queued or running task; kill the work subprocess if it still matches',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Task cancelled', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Task not found', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 409, description: 'Task cannot be cancelled', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function cancel(int $id): JsonResponse
    {
        $task = $this->taskOr404($id);

        $canceller = new TaskCanceller(new ProcessTreeKiller(new System()));
        $result = $canceller->cancel($task);
        if (!$result['cancelled']) {
            return new JsonResponse(['message' => $result['message']], 409);
        }

        return new JsonResponse(['data' => ['cancelled' => true]]);
    }
}
