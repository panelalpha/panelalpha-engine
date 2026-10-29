<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeployCancelRequest;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployTimings;
use App\Lib\Task\ProcessTreeKiller;
use App\Lib\Task\TaskCanceller;
use App\Models\Task;
use App\Models\User;
use App\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class DeployLogController extends Controller
{
    private function getDindUser(string $username): User
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'Deploy logs work only on container projects (template dind).'], 403));
        }

        return $user;
    }

    #[OA\Get(
        path: '/projects/{username}/deploy-log',
        summary: 'Poll the deploy log (JSON-lines) from a byte-like line offset',
        description: 'Returns a page of lines from `offset`. While `more` is true there are lines after this '
            .'page: ask again with `offset` set to `next_offset`. `max_bytes` bounds a page by size as well '
            .'as by line count.'
            ."\n\n"
            .'When the latest deploy failed and PanelAlpha monitoring knows how to fix that failure, '
            .'`problem` carries the fix (title, body_why, body_fix, fixed_in_version, ...); otherwise it is null.',
        x: ['mcp-description' => 'A page of the latest deploy log from `offset`. While `more` is true, call again '
            .'with `next_offset`. `problem` carries the fix when a failed deploy is a known failure.'],
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
            new OA\Parameter(
                name: 'max_bytes',
                in: 'query',
                required: false,
                description: 'Upper bound on the log text in one page, at least 1024. Omitted: up to 2000 lines, whatever their size.',
                schema: new OA\Schema(type: 'integer', minimum: 1024),
                x: ['mcp-default' => '49152', 'mcp-description' => 'Page size in bytes, at least 1024.'],
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deploy log chunk', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function show(string $username, Request $request): JsonResponse
    {
        $this->getDindUser($username);

        $offset = max(0, (int) $request->query('offset', 0));
        $maxBytes = $request->filled('max_bytes') ? max(1024, (int) $request->query('max_bytes')) : null;

        $logger = DeployLogger::current($username);
        if ($logger === null) {
            return new JsonResponse(['data' => self::NO_DEPLOY]);
        }

        $latest = $logger->readLatest();
        $read = $logger->read($offset, maxBytes: $maxBytes);
        $wantsBuild = $this->wantsBuildTimings($latest, $request);

        return new JsonResponse(['data' => [
            'id' => $latest['id'] ?? null,
            'status' => $latest['status'] ?? 'none',
            'stage' => $latest['stage'] ?? null,
            'stages' => $latest['stages'] ?? [],
            'lines' => $read['lines'],
            'next_offset' => $read['next_offset'],
            'more' => $read['more'],
            'error' => $latest['error'] ?? null,
            'started_at' => $latest['started_at'] ?? null,
            'finished_at' => $latest['finished_at'] ?? null,
            'timings' => DeployTimings::summarize(
                is_array($latest) ? $latest : [],
                $wantsBuild ? $logger->entries() : []
            ),
            'problem' => $latest['problem'] ?? null,
        ]]);
    }

    /** The deploy log of a project that has never deployed. */
    private const NO_DEPLOY = [
        'id' => null,
        'status' => 'none',
        'stage' => null,
        'stages' => [],
        'lines' => [],
        'next_offset' => 0,
        'more' => false,
        'error' => null,
        'started_at' => null,
        'finished_at' => null,
        'timings' => null,
        'problem' => null,
    ];

    /**
     * Stage durations come from latest.json and cost nothing; the build breakdown re-reads the whole log,
     * so it is computed only once the deploy has stopped or when asked for, keeping polling cheap.
     *
     * @param  array<string, mixed>|null  $latest
     */
    private function wantsBuildTimings(?array $latest, Request $request): bool
    {
        $finished = ($latest['finished_at'] ?? null) !== null;

        return $finished || $request->boolean('build_timings');
    }

    #[OA\Post(
        path: '/projects/{username}/deploy-cancel',
        summary: 'Cancel a running or queued deploy: mark cancelled, kill the subprocess tree, clean up',
        description: 'A deploy still waiting in the queue has no process yet: its task is cancelled, and the answer names it in `task_id`. '
            .'Container projects only (`template: dind`); a PHP hosting project answers 422 with the code `'.DeployCancelRequest::CONTAINER_ONLY.'`.',
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deploy cancellation requested', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 409, description: 'No running or queued deploy', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 422, description: 'The project is not a container project', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function cancel(string $username, DeployCancelRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        $result = DeployLogger::requestCancel($username);
        if (! $result['cancelled']) {
            return $this->cancelQueuedDeploy($username);
        }

        if ($result['pid'] !== null) {
            (new ProcessTreeKiller(new System))->kill($result['pid']);
        }

        $this->tearDownHalfStartedApp($user, $username);

        return new JsonResponse(['data' => ['cancelled' => true]]);
    }

    /** A deploy waiting in the queue has opened no log yet, so it is cancelled by its task. */
    private function cancelQueuedDeploy(string $username): JsonResponse
    {
        $task = Task::query()
            ->where('username', $username)
            ->whereIn('job_type', Task::DEPLOY_JOB_TYPES)
            ->where('status', Task::STATUS_QUEUED)
            ->orderByDesc('id')
            ->first();

        if (! $task instanceof Task) {
            return new JsonResponse(['message' => 'No running or queued deploy to cancel'], 409);
        }

        if (! (new TaskCanceller(new ProcessTreeKiller(new System)))->cancelQueued($task)) {
            return new JsonResponse(['message' => "Deploy task {$task->id} has just started; cancel it again."], 409);
        }

        return new JsonResponse(['data' => ['cancelled' => true, 'task_id' => $task->id]]);
    }

    /**
     * Best-effort teardown of the half-started app stack. It never destroys the account: the same
     * endpoint cancels a rebuild of a live application, and rolling back a failed creation is UserController's job.
     */
    private function tearDownHalfStartedApp(User $user, string $username): void
    {
        try {
            $user->project()->cancelRunningDeploy();
        } catch (\Exception $e) {
            Log::warning("Deploy cancel cleanup failed for {$username}: {$e->getMessage()}");
        }
    }
}
