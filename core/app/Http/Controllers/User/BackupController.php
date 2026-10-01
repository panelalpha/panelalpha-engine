<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackupRestoreRequest;
use App\Http\Resources\BackupCollection;
use App\Http\Resources\BackupResource;
use App\Jobs\CreateBackup;
use App\Jobs\DeleteBackup;
use App\Jobs\RestoreBackup;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class BackupController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/backups',
        summary: 'List backups for a project',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of backups', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Backup'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): BackupCollection|JsonResponse
    {
        $user = $this->projectOr404($username);

        $backups = BackupRecord::query()
            ->where('user_id', $user->id)
            ->with(['container', 'items'])
            ->orderByDesc('created_at')
            ->get();

        return new BackupCollection($backups);
    }

    #[OA\Post(
        path: '/projects/{username}/backups',
        summary: 'Create a project backup',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['container'],
            properties: [new OA\Property(property: 'container', description: 'Backup container id or name', type: 'string')],
        )),
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 202, description: 'Backup accepted', content: new OA\JsonContent(ref: '#/components/schemas/Backup')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(string $username, Request $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse([
                'message' => 'not supported',
            ], 422));
        }

        $validated = $request->validate([
            'container' => ['required', 'string'],
        ]);

        $container = BackupContainer::findByIdOrName((string) $validated['container']);
        if ($container === null) {
            abort(new JsonResponse([
                'message' => 'Backup container not found',
            ], 404));
        }

        $backup = BackupRecord::prepare($user, $container, 'api');
        $task = Task::start(
            jobType: CreateBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'backup'],
        );
        CreateBackup::dispatch($backup->id)->attachTask($task);

        $backup->refresh();
        $status = $backup->async_status ?? [];
        $status['task_id'] = $task->id;
        $backup->async_status = $status;
        $backup->save();
        $backup->load(['container', 'items']);

        return (new BackupResource($backup))
            ->response()
            ->setStatusCode(202);
    }

    #[OA\Get(
        path: '/projects/{username}/backups/{id}',
        summary: 'Get a project backup',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup details', content: new OA\JsonContent(ref: '#/components/schemas/Backup')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username, int $id): BackupResource|JsonResponse
    {
        $user = $this->projectOr404($username);

        $backup = BackupRecord::query()->find($id);
        if ($backup === null || $backup->user_id !== $user->id) {
            abort(new JsonResponse([
                'message' => 'Backup not found',
            ], 404));
        }

        $backup->loadMissing(['container', 'items']);

        return new BackupResource($backup);
    }

    #[OA\Post(
        path: '/projects/{username}/backups/{id}/restore',
        summary: 'Restore a project backup',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['confirm'],
            properties: [
                new OA\Property(property: 'confirm', type: 'boolean'),
                new OA\Property(property: 'only', type: 'object', nullable: true),
                new OA\Property(property: 'exclude', type: 'object', nullable: true),
            ],
        )),
        tags: ['Backups'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Restore accepted', content: new OA\JsonContent(ref: '#/components/schemas/Backup')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function restore(string $username, int $id, BackupRestoreRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse([
                'message' => 'not supported',
            ], 422));
        }

        $backup = BackupRecord::query()->find($id);
        if ($backup === null || $backup->user_id !== $user->id) {
            abort(new JsonResponse([
                'message' => 'Backup not found',
            ], 404));
        }

        $backup->loadMissing(['container', 'items']);

        if ($backup->backupStatus() !== 'completed' || $backup->items->isEmpty()) {
            abort(new JsonResponse([
                'message' => 'Backup is not complete',
            ], 422));
        }

        $only = $request->onlyFilter();
        $exclude = $request->excludeFilter();

        $backup->restore_details = ['only' => $only, 'exclude' => $exclude];
        $backup->setRestoreStatus('pending');
        $backup->error = null;
        $backup->save();

        $task = Task::start(
            jobType: RestoreBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'restore'],
        );
        RestoreBackup::dispatch($backup->id, $only, $exclude)->attachTask($task);

        $backup->refresh();
        $status = $backup->async_status ?? [];
        $status['task_id'] = $task->id;
        $backup->async_status = $status;
        $backup->save();

        return (new BackupResource($backup->fresh(['container', 'items'])))
            ->response()
            ->setStatusCode(202);
    }

    #[OA\Delete(
        path: '/projects/{username}/backups/{id}',
        summary: 'Delete a project backup',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Delete accepted', content: new OA\JsonContent(ref: '#/components/schemas/Backup')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, int $id): JsonResponse
    {
        $user = $this->projectOr404($username);

        $backup = BackupRecord::query()->find($id);
        if ($backup === null || $backup->user_id !== $user->id) {
            abort(new JsonResponse([
                'message' => 'Backup not found',
            ], 404));
        }

        $backup->loadMissing(['container', 'items']);

        $backup->setDeleteStatus('pending');
        $backup->error = null;
        $backup->save();

        $task = Task::start(
            jobType: DeleteBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'delete'],
        );
        DeleteBackup::dispatch($backup->id)->attachTask($task);

        $backup->refresh();
        $status = $backup->async_status ?? [];
        $status['task_id'] = $task->id;
        $backup->async_status = $status;
        $backup->save();

        return (new BackupResource($backup->fresh(['container', 'items'])))
            ->response()
            ->setStatusCode(202);
    }
}
