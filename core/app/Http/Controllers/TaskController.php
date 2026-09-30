<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskLogCollection;
use App\Http\Resources\TaskLogResource;
use App\Http\Resources\TaskResource;
use App\System;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
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
        $task = Task::find($id);
        if (!$task) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

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
        $task = Task::find($id);
        if (!$task) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        $afterId = max(0, (int) $request->query('after_id', 0));
        $since = $request->query('since');
        $page = $task->logsPage($afterId, is_array($since) ? null : $since);
        $nextAfterId = $page->isEmpty() ? $afterId : (int) $page->last()->id;

        return new TaskLogCollection($page, $task->status, $nextAfterId);
    }

    #[OA\Get(
        path: '/tasks/{id}/logs/stream',
        description: 'Chunked NDJSON without Content-Length. Each log line is one JSON object; '
            . 'a final `{"type":"finish","status":"..."}` frame closes the stream when the task '
            . 'is terminal and no further lines remain. Optional `since` / `after_id` skip the '
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
        ],
    )]
    public function streamLogs(int $id, Request $request): StreamedResponse|JsonResponse
    {
        $task = Task::find($id);
        if (!$task) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        $afterId = max(0, (int) $request->query('after_id', 0));
        $since = $request->query('since');
        if (is_array($since)) {
            $since = null;
        }

        ignore_user_abort(true);
        set_time_limit(0);

        return response()->stream(function () use ($task, $afterId, $since) {
            // Production: drain FPM/proxy buffers so each NDJSON line flushes.
            // Under PHPUnit, TestResponse::streamedContent() owns a buffer —
            // wiping it makes the capture fail and marks the test risky.
            if (!app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }

            $emit = static function (array $frame): void {
                echo json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                if (!app()->runningUnitTests()) {
                    flush();
                }
            };

            $cursor = $afterId;
            $sinceCursor = $since;

            while (true) {
                if (connection_aborted()) {
                    return;
                }

                $task->refresh();
                $page = $task->logsPage($cursor, $sinceCursor);

                foreach ($page as $row) {
                    $parsed = TaskLogResource::parsePayload($row->log);
                    $emit([
                        'id' => $row->id,
                        'log' => $parsed['msg'],
                        'level' => $parsed['level'],
                        'stage' => $parsed['stage'],
                        'created_at' => optional($row->created_at)?->toIso8601String(),
                    ]);
                    $cursor = (int) $row->id;
                    // Once we have an id cursor, drop the time filter so rows
                    // that share the same second are not re-fetched.
                    $sinceCursor = null;
                }

                if ($task->isTerminal() && $page->isEmpty()) {
                    $emit([
                        'type' => 'finish',
                        'status' => $task->status,
                    ]);

                    return;
                }

                if ($page->isEmpty()) {
                    usleep(200000);
                }
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
        ]);
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
        $task = Task::find($id);
        if (!$task) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        $canceller = new TaskCanceller(new ProcessTreeKiller(new System()));
        $result = $canceller->cancel($task);
        if (!$result['cancelled']) {
            return new JsonResponse(['message' => $result['message']], 409);
        }

        return new JsonResponse(['data' => ['cancelled' => true]]);
    }
}
