<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\System;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployTimings;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class DeployLogController extends Controller
{
    private function getDindUser(string $username): User
    {
        $user = $this->projectOr404($username, 'Not found');
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'Deploy logs are only available for dind users'], 403));
        }

        return $user;
    }

    #[OA\Get(
        path: '/projects/{username}/deploy-log',
        summary: 'Poll the deploy log (JSON-lines) from a byte-like line offset',
        description: 'Returns a page of lines from `offset`. While `more` is true there are lines after this '
            . 'page: ask again with `offset` set to `next_offset`. `max_bytes` bounds a page by size as well '
            . 'as by line count.'
            . "\n\n"
            . 'When the latest deploy failed and PanelAlpha monitoring knows how to fix that failure, '
            . '`problem` carries the fix (title, body_why, body_fix, fixed_in_version, ...); otherwise it is null.',
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
                x: ['mcp-default' => '49152'],
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

        $offset = max(0, (int)$request->query('offset', 0));
        $maxBytes = $request->filled('max_bytes') ? max(1024, (int)$request->query('max_bytes')) : null;

        $logger = DeployLogger::current($username);
        if ($logger === null) {
            return new JsonResponse(['data' => [
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
            ]]);
        }

        $latest = $logger->readLatest();
        $read = $logger->read($offset, maxBytes: $maxBytes);

        // Stage durations come from latest.json and cost nothing. The build
        // breakdown has to re-read the whole log — hundreds of kilobytes on a
        // real build — so it is computed once the deploy has stopped, or when
        // a caller asks for it outright. Polling a running deploy stays cheap.
        $finished = ($latest['finished_at'] ?? null) !== null;
        $wantsBuild = $finished || $request->boolean('build_timings');

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
            // Monitoring's fix for this failure, when it has one: see DeployLogger::finish().
            'problem' => $latest['problem'] ?? null,
        ]]);
    }

    #[OA\Post(
        path: '/projects/{username}/deploy-cancel',
        summary: 'Cancel a running deploy: mark cancelled, kill the subprocess tree, clean up',
        security: [['bearerAuth' => []]],
        tags: ['Deploy'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deploy cancellation requested', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 409, description: 'No running deploy', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function cancel(string $username): JsonResponse
    {
        $user = $this->getDindUser($username);

        $result = DeployLogger::requestCancel($username);
        if (!$result['cancelled']) {
            return new JsonResponse(['message' => 'No running deploy to cancel'], 409);
        }

        if ($result['pid'] !== null) {
            $this->killProcessTree($result['pid'], new System());
        }

        // Best-effort teardown of the half-started app stack. Cancelling a
        // deploy must never destroy the account itself — the same endpoint is
        // used to cancel a rebuild of a live application. Rolling a *failed
        // account creation* back is UserController's job, not this one's.
        try {
            $user->project()->abortRunningDeploy();
        } catch (\Exception $e) {
            Log::warning("Deploy cancel cleanup failed for {$username}: {$e->getMessage()}");
        }

        return new JsonResponse(['data' => ['cancelled' => true]]);
    }

    /**
     * SIGTERM the process tree rooted at $pid, escalating to SIGKILL after a
     * grace period. Runs inside the core container (root, pid: host) where all
     * deploy subprocesses live.
     */
    private function killProcessTree(int $pid, System $system): void
    {
        $pids = $this->collectDescendants($pid, $system);
        $pids[] = $pid;

        $list = implode(' ', $pids);
        $system->runProcess("kill -TERM {$list} 2>/dev/null; true");

        $deadline = microtime(true) + 5;
        $alive = $pids;
        while (!empty($alive) && microtime(true) < $deadline) {
            $alive = [];
            foreach ($pids as $p) {
                $check = $system->runProcess("kill -0 {$p} 2>/dev/null");
                if ($check->getExitCode() === 0) {
                    $alive[] = $p;
                }
            }
            if (!empty($alive)) {
                usleep(200000);
            }
        }

        if (!empty($alive)) {
            $system->runProcess('kill -KILL ' . implode(' ', $alive) . ' 2>/dev/null; true');
        }
    }

    /**
     * @return array<int>
     */
    private function collectDescendants(int $pid, System $system): array
    {
        $result = [];
        $queue = [$pid];
        while (!empty($queue)) {
            $parent = array_shift($queue);
            $process = $system->runProcess(['ps', '-o', 'pid=', '--ppid', (string)$parent]);
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $child = (int)trim($line);
                if ($child > 0 && !in_array($child, $result, true)) {
                    $result[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $result;
    }
}
