<?php

namespace App\Http\Controllers\User;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BackupScheduleUpdateRequest;
use App\Http\Resources\BackupScheduleResource;
use App\Models\BackupContainer;
use App\Models\BackupSchedule;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class BackupScheduleController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/backup-schedule',
        summary: 'Get the automatic backup schedule of a project',
        description: 'One schedule per project. Answers `{data: null}` when the project has none. Available for PHP hosting and DinD projects.',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The project backup schedule or null', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BackupSchedule', nullable: true)],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username): JsonResponse
    {
        $user = $this->projectOr404($username);
        $schedule = $this->scheduleFor($user->id);
        if ($schedule === null) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => (new BackupScheduleResource($schedule))->toArray(request())]);
    }

    #[OA\Put(
        path: '/projects/{username}/backup-schedule',
        summary: 'Create or replace the automatic backup schedule of a project',
        description: 'Cron is a five-field expression evaluated in UTC and may run at most once an hour. An unresolvable container answers 422 `container_not_found`, a schedule more frequent than hourly 422 `cron_too_frequent`.',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['cron'],
            properties: [
                new OA\Property(property: 'container', description: 'Backup container id or name. Without it an existing schedule keeps its store, and a new one uses the default store (`default_backup_container` in GET /system/info); a new one on a host with no default answers 422 `no_default_container`.', type: 'string'),
                new OA\Property(property: 'cron', description: 'Five-field cron expression in UTC, at most hourly.', type: 'string', example: '0 3 * * *'),
                new OA\Property(property: 'keep_last', description: 'Completed scheduled backups to keep (1-365).', type: 'integer', minimum: 1, maximum: 365, example: 7),
                new OA\Property(property: 'files', type: 'boolean', example: true),
                new OA\Property(property: 'databases', type: 'boolean', example: true),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The stored schedule', content: new OA\JsonContent(ref: '#/components/schemas/BackupSchedule')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(string $username, BackupScheduleUpdateRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);
        /** @var BackupSchedule $schedule */
        $schedule = BackupSchedule::query()->firstOrNew(['user_id' => $user->id]);
        $container = $this->existingContainer($request->backupContainer($schedule->container));
        $cron = $this->acceptableCron($request->cron());

        $schedule->fill([
            'container_id' => $container->id,
            'cron' => $cron,
            'keep_last' => $request->keepLast(),
            'files' => $request->contents()['files'],
            'databases' => $request->contents()['databases'],
            'enabled' => $request->enabled(),
            'last_error' => null,
        ]);
        $schedule->advanceNextRun();
        $schedule->save();

        return new JsonResponse(['data' => (new BackupScheduleResource($schedule))->toArray(request())]);
    }

    #[OA\Delete(
        path: '/projects/{username}/backup-schedule',
        summary: 'Delete the automatic backup schedule of a project',
        description: 'Idempotent: answers 204 whether or not the project had a schedule. '
            .'The schedule goes and no automatic backup is taken until a new one is set; the backups it already '
            .'took stay. This cannot be undone. Ask the operator to confirm first.',
        security: [['bearerAuth' => []]],
        tags: ['Backups'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Schedule removed'),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username): Response
    {
        $user = $this->projectOr404($username);
        BackupSchedule::query()->where('user_id', $user->id)->delete();

        return response()->noContent();
    }

    private function scheduleFor(int $userId): ?BackupSchedule
    {
        return BackupSchedule::query()->where('user_id', $userId)->first();
    }

    private function existingContainer(?BackupContainer $container): BackupContainer
    {
        if ($container === null) {
            throw ProblemException::one('container', 'container_not_found', 'Backup container not found');
        }

        return $container;
    }

    private function acceptableCron(string $cron): string
    {
        if (! CronExpression::isValidExpression($cron)) {
            throw ProblemException::one('cron', 'invalid_cron', 'The cron expression is not valid.');
        }
        if (! BackupSchedule::isAtMostHourly($cron)) {
            throw ProblemException::one('cron', 'cron_too_frequent', 'Automatic backups may run at most once an hour.');
        }

        return $cron;
    }
}
