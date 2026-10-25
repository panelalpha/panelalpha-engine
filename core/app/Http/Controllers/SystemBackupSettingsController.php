<?php

namespace App\Http\Controllers;

use App\Http\Requests\BackupSettingsUpdateRequest;
use App\Http\Resources\BackupSettingsResource;
use App\Lib\Backup\ArchivePriority;
use OpenApi\Attributes as OA;

/**
 * How hard backups and restores may push on this host. Global: the setting is
 * one host's, so a token scoped to some projects is refused here like on every
 * other engine-wide route.
 */
class SystemBackupSettingsController extends Controller
{
    #[OA\Get(
        path: '/system/backup-settings',
        summary: 'How hard backups and restores may push on this host',
        description: 'The niceness and IO class the archive commands run at (`tar` of a project, a home or a '
            .'volume, and the volume extraction on restore). `nice` is 0-19, higher meaning the scheduler runs it only '
            .'when nothing else wants the CPU; `ionice_class` is 2 (best-effort) or 3 (idle, the disk goes to live '
            .'sites first). null means the engine\'s own priority, which is the default for both. Refused with 403 '
            .'for a token scoped to some projects: it is not a project route.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Reports how hard backups and restores may push on this host: the niceness (0-19) and IO class (2 best-effort, 3 idle) their archive commands run at, null when they run at the engine\'s own priority. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The backup settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BackupSettings')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): BackupSettingsResource
    {
        return new BackupSettingsResource(ArchivePriority::configured());
    }

    #[OA\Put(
        path: '/system/backup-settings',
        summary: 'Set how hard backups and restores may push on this host',
        description: 'Only the fields present are changed. `nice` is 0-19; `ionice_class` is 2 (best-effort) or 3 '
            .'(idle). null switches that half off again, so the archive runs at the engine\'s own priority for it. '
            .'A change applies to the next backup or restore; one already running keeps the priority it started '
            .'with. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lowers or restores the priority of backup and restore archive commands on this host. Only the fields given are changed; null switches that half off. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'nice', type: 'integer', nullable: true, minimum: ArchivePriority::NICE_MIN, maximum: ArchivePriority::NICE_MAX, example: 10),
                new OA\Property(property: 'ionice_class', type: 'integer', nullable: true, enum: ArchivePriority::IONICE_CLASSES, example: 3),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The settings as stored', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BackupSettings')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'A value out of range', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(BackupSettingsUpdateRequest $request): BackupSettingsResource
    {
        $values = $request->merged(ArchivePriority::configured());

        return new BackupSettingsResource(ArchivePriority::apply($values['nice'], $values['ionice_class']));
    }
}
