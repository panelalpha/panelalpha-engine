<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackupContainerStoreRequest;
use App\Http\Requests\BackupContainerUpdateRequest;
use App\Http\Resources\BackupContainerCollection;
use App\Http\Resources\BackupContainerResource;
use App\Jobs\DeleteBackupContainer;
use App\Models\BackupContainer;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

class BackupContainerController extends Controller
{

    #[OA\Get(
        path: '/backup-containers',
        summary: 'List backup containers',
        security: [['bearerAuth' => []]],
        tags: ['Backup Containers'],
        responses: [
            new OA\Response(response: 200, description: 'List of backup containers', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BackupContainer'))],
            )),
        ],
    )]
    public function index(Request $request): BackupContainerCollection
    {
        /** @var Collection<array-key, BackupContainer> $containers */
        $containers = BackupContainer::query()
            ->orderBy('name')
            ->get();

        return new BackupContainerCollection($containers);
    }

    #[OA\Get(
        path: '/backup-containers/{id}',
        summary: 'Get a backup container',
        security: [['bearerAuth' => []]],
        tags: ['Backup Containers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Backup container', content: new OA\JsonContent(ref: '#/components/schemas/BackupContainer')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(int $id): BackupContainerResource
    {
        /** @var BackupContainer $container */
        $container = BackupContainer::findOrFail($id);
        return new BackupContainerResource($container);
    }

    #[OA\Post(
        path: '/backup-containers',
        summary: 'Create a backup container',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'driver', 'location'],
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'driver', type: 'string', enum: ['local', 's3', 'ftp', 'ftps', 'sftp']),
                new OA\Property(property: 'location', type: 'string'),
                new OA\Property(property: 'credentials', type: 'object', nullable: true),
            ],
        )),
        tags: ['Backup Containers'],
        responses: [
            new OA\Response(response: 200, description: 'Backup container created', content: new OA\JsonContent(ref: '#/components/schemas/BackupContainer')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(BackupContainerStoreRequest $request): BackupContainerResource
    {
        /**
         * @var array{
         *   name: string,
         *   driver: string,
         *   location: string,
         *   credentials?: ?array
         * } $validated
         */
        $validated = $request->validated();

        /** @var BackupContainer $container */
        $container = BackupContainer::create([
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'location' => $validated['location'],
            'credentials' => $request->credentials(),
        ]);

        return new BackupContainerResource($container);
    }

    #[OA\Put(
        path: '/backup-containers/{id}',
        summary: 'Update a backup container',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'driver', type: 'string', enum: ['local', 's3', 'ftp', 'ftps', 'sftp']),
                new OA\Property(property: 'location', type: 'string'),
                new OA\Property(property: 'credentials', type: 'object', nullable: true),
            ],
        )),
        tags: ['Backup Containers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Backup container updated', content: new OA\JsonContent(ref: '#/components/schemas/BackupContainer')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(int $id, BackupContainerUpdateRequest $request): BackupContainerResource
    {
        $container = BackupContainer::findOrFail($id);

        /**
         * @var array{
         *   name?: string,
         *   driver?: string,
         *   location?: string,
         *   credentials?: ?array
         * } $validated
         */
        $validated = $request->validated();

        foreach (['name', 'driver', 'location'] as $field) {
            if (array_key_exists($field, $validated)) {
                $container->{$field} = $validated[$field];
            }
        }
        $this->applyCredentials($container, $validated, $request);

        $container->save();

        return new BackupContainerResource($container);
    }

    /**
     * Sent credentials are normalised for the container's driver; a switch to `local` without any drops the old ones.
     *
     * @param array{driver?: string, credentials?: ?array} $validated
     */
    private function applyCredentials(BackupContainer $container, array $validated, BackupContainerUpdateRequest $request): void
    {
        if (array_key_exists('credentials', $validated)) {
            $container->credentials = $request->normalizeCredentials(
                $container->driver,
                $validated['credentials'],
            );
        } elseif (array_key_exists('driver', $validated) && $validated['driver'] === 'local') {
            $container->credentials = null;
        }
    }

    #[OA\Delete(
        path: '/backup-containers/{id}',
        summary: 'Delete a backup container',
        description: 'A store that still holds backups is refused with 409 unless `delete_backups` is set, which removes the backups with the store (as an asynchronous task, answered 202 with a task_id).',
        security: [['bearerAuth' => []]],
        tags: ['Backup Containers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(
                name: 'delete_backups',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean'),
                description: 'Delete the store even though it holds backups, removing them too.',
                x: ['mcp-description' => 'Set to remove the store and its backups together; without it a non-empty store is refused with 409.'],
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup container deleted', content: new OA\JsonContent(ref: '#/components/schemas/BackupContainer')),
            new OA\Response(response: 202, description: 'Deletion of the container and its backups started', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object'),
                    new OA\Property(property: 'task_id', type: 'string'),
                ],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Container has backups and delete_backups was not set', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(int $id, Request $request): BackupContainerResource|JsonResponse
    {
        $container = BackupContainer::findOrFail($id);
        $deleteBackups = filter_var($request->query('delete_backups'), FILTER_VALIDATE_BOOL);

        if ($container->schedules()->exists()) {
            return new JsonResponse([
                'message' => 'Cannot delete backup container while a backup schedule uses it.',
                'code' => 'container_in_use',
            ], 409);
        }

        if (!$container->backups()->exists()) {
            $container->delete();

            return new BackupContainerResource($container);
        }
        if (!$deleteBackups) {
            return new JsonResponse([
                'message' => 'Cannot delete backup container while backups exist. Pass delete_backups to remove them with the container.',
            ], 409);
        }

        return $this->deleteWithBackups($container, $request);
    }

    /** Removes the container and its backups in a background task, answered 202 with its id. */
    private function deleteWithBackups(BackupContainer $container, Request $request): JsonResponse
    {
        $task = Task::start(
            jobType: DeleteBackupContainer::class,
            queue: 'default',
            username: null,
            details: ['container_id' => $container->id, 'action' => 'delete_container'],
        );
        DeleteBackupContainer::dispatch($container->id)->attachTask($task);

        return new JsonResponse([
            'data' => (new BackupContainerResource($container))->toArray($request),
            'task_id' => $task->id,
        ], 202);
    }

    #[OA\Post(
        path: '/backup-containers/{id}/test',
        summary: 'Test backup container connectivity',
        security: [['bearerAuth' => []]],
        tags: ['Backup Containers'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Connection test succeeded', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'ok', type: 'boolean')], type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Connection test failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function test(int $id): JsonResponse
    {
        $container = BackupContainer::findOrFail($id);

        try {
            $container->storage()->test();
        } catch (Throwable $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
            ], 422);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
