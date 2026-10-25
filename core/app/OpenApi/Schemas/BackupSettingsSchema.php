<?php

namespace App\OpenApi\Schemas;

use App\Lib\Backup\ArchivePriority;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'BackupSettings',
    properties: [
        new OA\Property(
            property: 'nice',
            description: 'Niceness of the backup and restore archive commands, 0-19. null runs them at the '
                .'engine\'s own priority.',
            type: 'integer',
            nullable: true,
            minimum: ArchivePriority::NICE_MIN,
            maximum: ArchivePriority::NICE_MAX,
            example: 10,
        ),
        new OA\Property(
            property: 'ionice_class',
            description: 'IO class of those commands: 2 best-effort, 3 idle. null takes no IO class.',
            type: 'integer',
            nullable: true,
            enum: ArchivePriority::IONICE_CLASSES,
            example: 3,
        ),
    ],
    type: 'object',
)]
class BackupSettingsSchema {}
