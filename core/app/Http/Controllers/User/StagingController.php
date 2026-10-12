<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectPushRequest;
use App\Http\Requests\ProjectStagingRequest;
use App\Http\Resources\UserResource;
use App\Jobs\CreateStaging;
use App\Jobs\PushState;
use App\Lib\Host\ProjectMemory;
use App\System;
use App\System\Projects;
use App\Lib\Helper;
use App\Models\Domain;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class StagingController extends Controller
{
    #[OA\Post(
        path: '/projects/{username}/staging',
        description: 'Creates a pending staging project linked to the live source via `staging`, '
            . 'then copies files and volume data asynchronously. Source and destination must use the same '
            . 'project template (e.g. dind). Returns HTTP 202 with the destination project; poll '
            . '`GET /projects/{dest}` until `async_status.staging` is `completed` or `failed`. '
            . 'CLI equivalent: `php artisan project:staging`.',
        summary: 'Create a linked staging mirror of a live project',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'new_username', type: 'string', nullable: true),
                new OA\Property(property: 'domain', type: 'string', nullable: true),
                new OA\Property(
                    property: 'copy_databases',
                    description: 'Copy the source project\'s MySQL databases under the destination prefix. Defaults to true.',
                    type: 'boolean',
                    nullable: true,
                ),
            ],
        )),
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 202, description: 'Staging creation accepted', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Project busy (staging or push in progress)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function staging(string $username, ProjectStagingRequest $request): JsonResponse
    {
        $source = $this->projectOr404($username);

        try {
            Projects::assertIdle($source);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                abort(new JsonResponse(['message' => $e->getMessage()], 409));
            }
            throw $e;
        }

        /** @var array{new_username?: ?string, domain?: ?string, copy_databases?: ?bool} $params */
        $params = $request->validated();
        $system = new System();
        $copyDatabases = $params['copy_databases'] ?? true;

        $newUsername = !empty($params['new_username']) ? $params['new_username'] : null;
        if ($newUsername === null) {
            $newUsername = Helper::generateCloneUsername($source->username);
            if ($newUsername === null) {
                throw ValidationException::withMessages([
                    'new_username' => 'Could not generate an available username for the clone.',
                ]);
            }
        }

        if (User::existsByUsername($newUsername)) {
            throw ValidationException::withMessages([
                'new_username' => 'User already exists.',
            ]);
        }
        if (!$system->isUsernameAvailable($newUsername)) {
            throw ValidationException::withMessages([
                'new_username' => 'Username not available.',
            ]);
        }

        $destDomain = !empty($params['domain']) ? $params['domain'] : null;
        if ($destDomain === null) {
            $destDomain = Helper::generateCloneDomain($source->domain);
            if ($destDomain === null) {
                throw ValidationException::withMessages([
                    'domain' => 'Could not generate an available staging domain for the clone.',
                ]);
            }
        } elseif (Str::startsWith($destDomain, 'www.')) {
            $destDomain = Str::after($destDomain, 'www.');
        }

        if (Domain::domainOrAliasExists($destDomain)) {
            throw ValidationException::withMessages([
                'domain' => 'Domain already exists.',
            ]);
        }

        $probe = User::make([
            'username' => $newUsername,
            'domain'   => $destDomain,
            'email'    => $source->email,
        ]);
        if ($probe->project()->hostingExists()) {
            throw ValidationException::withMessages([
                'new_username' => 'Username not available.',
            ]);
        }

        // A staging copy keeps the source's memory limit, which must still fit this host.
        ProjectMemory::assertFits(ProjectMemory::resolve($source->getMemoryLimit()));

        Projects::assertDatabaseNamesFit($source, $newUsername . '_');

        $dest = $source->makePendingStaging($newUsername, $destDomain);

        // The destination account is deleted on a failed copy (see
        // CreateStaging::failed()), so this task is the only record of the
        // failure that survives -- without it, project_get 404s and there
        // is nothing left anywhere to explain why.
        $task = Task::start(
            jobType: CreateStaging::class,
            queue: 'default',
            username: $dest->username,
            details: [
                'source' => $source->username,
                'dest' => $dest->username,
                'domain' => $destDomain,
                'action' => 'staging',
                'copy_databases' => $copyDatabases,
            ],
        );
        CreateStaging::dispatch($dest->username, (int) $dest->getKey(), $copyDatabases)->attachTask($task);

        return (new UserResource($dest->loadMissing(['liveUser', 'stagingUser'])))
            ->additional(['task_id' => $task->id])
            ->response()
            ->setStatusCode(202);
    }

    #[OA\Post(
        path: '/projects/{username}/push',
        description: 'Copies application state from the source to a paired live or staging project '
            . '(the pair is linked by `staging`), then swaps it in asynchronously. Source and destination '
            . 'must use the same project template. Returns HTTP 202 with the target project and a `task_id`; '
            . 'poll `GET /tasks/{task_id}` for the push Task, or `GET /projects/{target}` until '
            . '`async_status.push` is `completed` or `failed`. '
            . 'CLI equivalent: `php artisan project:push`.',
        summary: 'Push project state to a paired staging or live project',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['target'],
            properties: [
                new OA\Property(property: 'target', type: 'string'),
                new OA\Property(
                    property: 'files',
                    description: 'Replace the target home directory with the source files. Defaults to true.',
                    type: 'boolean',
                    nullable: true,
                ),
                new OA\Property(
                    property: 'databases',
                    description: 'Replace the target MySQL databases with the source data. Defaults to true.',
                    type: 'boolean',
                    nullable: true,
                ),
            ],
        )),
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 202, description: 'Push accepted', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Project busy (staging or push in progress)', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function push(string $username, ProjectPushRequest $request): JsonResponse
    {
        $from = $this->projectOr404($username);

        /** @var array{target: string, files?: ?bool, databases?: ?bool} */
        $params = $request->validated();
        $to = $this->projectOr404($params['target']);
        $files = $params['files'] ?? true;
        $databases = $params['databases'] ?? true;

        try {
            Projects::assertIdle($from);
            Projects::assertIdle($to);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                abort(new JsonResponse(['message' => $e->getMessage()], 409));
            }
            throw $e;
        }

        $to->mergeAsyncStatus([
            'push'   => 'running',
            'source' => 'api',
        ]);
        $to->setDetails(['error' => null]);
        $to->save();

        $task = Task::start(
            jobType: PushState::class,
            queue: 'default',
            username: $to->username,
            details: [
                'action' => 'push',
                'source' => $from->username,
                'target' => $to->username,
                'files' => $files,
                'databases' => $databases,
            ],
        );
        PushState::dispatch($from->username, $to->username, $files, $databases)->attachTask($task);

        return (new UserResource($to->loadMissing(['liveUser', 'stagingUser'])))
            ->additional(['task_id' => $task->id])
            ->response()
            ->setStatusCode(202);
    }
}
