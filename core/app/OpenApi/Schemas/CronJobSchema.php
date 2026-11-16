<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CronJob',
    properties: [
        new OA\Property(property: 'hash', type: 'string', example: 'abc123def456', description: 'Addresses the job in update and delete; changes whenever the job does'),
        new OA\Property(property: 'command', type: 'string', example: '/usr/bin/php /home/johndoe/script.php'),
        new OA\Property(property: 'minute', type: 'string', example: '0'),
        new OA\Property(property: 'hour', type: 'string', example: '*'),
        new OA\Property(property: 'day_of_month', type: 'string', example: '*'),
        new OA\Property(property: 'month', type: 'string', example: '*'),
        new OA\Property(property: 'day_of_week', type: 'string', example: '*'),
        new OA\Property(property: 'service', type: 'string', nullable: true, example: 'app', description: 'The Compose service a container project\'s job runs in; null on PHP hosting'),
    ],
    type: 'object',
)]
class CronJobSchema {}
