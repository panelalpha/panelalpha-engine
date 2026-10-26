<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CronJob',
    properties: [
        new OA\Property(property: 'hash', type: 'string', example: 'abc123def456'),
        new OA\Property(property: 'schedule', type: 'string', example: '0 * * * *', description: 'Cron schedule expression'),
        new OA\Property(property: 'command', type: 'string', example: '/usr/bin/php /home/johndoe/script.php'),
        new OA\Property(property: 'enabled', type: 'boolean', example: true),
    ],
    type: 'object',
)]
class CronJobSchema {}
