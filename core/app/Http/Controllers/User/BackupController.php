<?php

namespace App\Http\Controllers\User;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupRestoreRequest;
use App\Http\Requests\BackupStoreRequest;
use App\Http\Resources\BackupCollection;
use App\Http\Resources\BackupResource;
use App\Integrations\Storage\BackupStorage;
use App\Integrations\Storage\Local;
use App\Jobs\CreateBackup;
use App\Jobs\DeleteBackup;
use App\Jobs\RestoreBackup;
use App\Lib\Project\ProjectRebuild;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Task;
use App\Models\User;
use App\OpenApi\ApiDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/backups',
        summary: 'List backups for a project',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['manual', 'scheduled'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of backups', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Backup'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function index(string $username, Request $request): BackupCollection|JsonResponse
    {
        $user = $this->projectOr404($username);

        $query = BackupRecord::query()
            ->where('user_id', $user->id)
            ->with(['container', 'items'])
            ->orderByDesc('created_at');

        $validated = $request->validate(['kind' => ['sometimes', 'in:manual,scheduled']]);
        if (isset($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }

        return new BackupCollection($query->get());
    }

    #[OA\Post(
        path: '/projects/{username}/backups',
        summary: 'Create a project backup',
        description: 'Supported for PHP hosting and DinD projects alike. On DinD the `databases` flag also controls the project volumes. A request that selects neither files nor databases is refused with 422 `nothing_to_back_up`.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['container'],
            properties: [
                new OA\Property(property: 'container', description: 'Backup container id or name', type: 'string'),
                new OA\Property(property: 'files', description: 'Include the project files (default true).', type: 'boolean'),
                new OA\Property(property: 'databases', description: 'Include the databases; on DinD this also includes the volumes (default true).', type: 'boolean'),
                new OA\Property(property: 'note', description: 'Free-form note shown with the backup.', type: 'string', nullable: true, maxLength: 255),
            ],
        )),
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 202, description: 'Backup accepted', content: new OA\JsonContent(ref: '#/components/schemas/Backup')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(string $username, BackupStoreRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        $this->assertIdle($user);

        $contents = $request->contents();
        if (!$contents['files'] && !$contents['databases']) {
            throw ProblemException::one('files', 'nothing_to_back_up', 'Choose files or databases to back up.');
        }

        $container = BackupContainer::findByIdOrName((string) $request->validated('container'));
        if ($container === null) {
            abort(new JsonResponse([
                'message' => 'Backup container not found',
            ], 404));
        }

        $backup = BackupRecord::prepare($user, $container, 'api', $contents, $request->note());
        $task = Task::start(
            jobType: CreateBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'backup', 'kind' => $backup->kind],
        );
        CreateBackup::dispatch($backup->id)->attachTask($task);

        $this->recordTask($backup, $task);
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

        $backup = $this->backupOr404($user, $id);

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
                new OA\Property(property: 'delete_existing_files', description: 'Default true: swap the home for the restored copy. False extracts over the existing tree.', type: 'boolean'),
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
            new OA\Response(response: 409, description: 'Backup is not completed. ' . ApiDefinitions::PROTECTED_RESPONSE, content: new OA\JsonContent(ref: '#/components/schemas/ProjectConflictResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function restore(string $username, int $id, BackupRestoreRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);

        $backup = $this->backupOr404($user, $id);

        if ($backup->backupStatus() !== 'completed' || $backup->items->isEmpty()) {
            return $this->notCompleted();
        }

        $only = $request->onlyFilter();
        $exclude = $request->excludeFilter();
        $this->assertVolumesApplicable($user, $only, $exclude);
        $deleteExistingFiles = $request->deleteExistingFiles();

        $this->assertIdle($user);

        $backup->restore_details = [
            'only' => $only,
            'exclude' => $exclude,
            'delete_existing_files' => $deleteExistingFiles,
        ];
        $backup->setRestoreStatus('pending');
        $backup->error = null;
        $backup->save();

        $task = Task::start(
            jobType: RestoreBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'restore'],
        );
        RestoreBackup::dispatch($backup->id, $only, $exclude, $deleteExistingFiles)->attachTask($task);

        $this->recordTask($backup, $task);

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

        $backup = $this->backupOr404($user, $id);

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

        $this->recordTask($backup, $task);

        return (new BackupResource($backup->fresh(['container', 'items'])))
            ->response()
            ->setStatusCode(202);
    }

    #[OA\Get(
        path: '/projects/{username}/backups/{id}/items/{item}/download',
        summary: 'Download a backup item',
        description: 'Streams the stored object. Local containers serve the file directly (Range supported); S3 and FTP stream through the Engine. Available for PHP hosting and DinD projects. Answers 409 `backup_not_completed` while the backup is not completed, 404 when the backup or item is not this project\'s.',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup item stream', content: new OA\MediaType(mediaType: 'application/gzip', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Backup is not completed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function download(string $username, int $id, int $item): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $user = $this->projectOr404($username);
        $backup = $this->backupOr404($user, $id);

        if ($backup->backupStatus() !== 'completed') {
            return $this->notCompleted();
        }

        $backupItem = $backup->items->firstWhere('id', $item);
        if ($backupItem === null) {
            return new JsonResponse(['message' => 'Backup item not found'], 404);
        }

        $storage = $backup->container->storage();
        $remotePath = (string) $backupItem->remote_path;
        $filename = $username . '-backup-' . $backup->id . '-' . basename($remotePath);
        if ($storage instanceof Local) {
            $response = response()->download($storage->path($remotePath), $filename);
            $response->headers->set('Content-Type', 'application/gzip');

            return $response;
        }

        return response()->streamDownload(
            $this->streamBody($storage, $remotePath),
            $filename,
            [
                'Content-Type' => 'application/gzip',
                'Content-Length' => (string) $storage->size($remotePath),
                'Accept-Ranges' => 'none',
            ],
        );
    }

    private function streamBody(BackupStorage $storage, string $remotePath): callable
    {
        return function () use ($storage, $remotePath): void {
            $stream = $storage->readStream($remotePath);
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        };
    }

    private function notCompleted(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Backup is not complete',
            'code' => 'backup_not_completed',
        ], 409);
    }

    private function assertVolumesApplicable(User $user, array $only, array $exclude): void
    {
        if ($user->project()->runsInDind()) {
            return;
        }

        if (($only['volumes'] ?? []) !== []) {
            throw ProblemException::one('only.volumes', 'volumes_not_applicable', 'A PHP hosting backup has no volumes.');
        }
        if (($exclude['volumes'] ?? []) !== []) {
            throw ProblemException::one('exclude.volumes', 'volumes_not_applicable', 'A PHP hosting backup has no volumes.');
        }
    }

    /** A 409 now rather than a queued task that fails: backup and restore stop the stack and need the deploy lock. */
    private function assertIdle(User $user): void
    {
        app(ProjectRebuild::class)->assertIdle($user);
    }

    /** The project's own backup with its container and items loaded, or a 404. */
    private function backupOr404(User $user, int $id): BackupRecord
    {
        $backup = BackupRecord::query()->find($id);
        if ($backup === null || $backup->user_id !== $user->id) {
            abort(new JsonResponse([
                'message' => 'Backup not found',
            ], 404));
        }

        $backup->loadMissing(['container', 'items']);

        return $backup;
    }

    private function recordTask(BackupRecord $backup, Task $task): void
    {
        $backup->refresh();
        $status = $backup->async_status ?? [];
        $status['task_id'] = $task->id;
        $backup->async_status = $status;
        $backup->save();
    }
}
