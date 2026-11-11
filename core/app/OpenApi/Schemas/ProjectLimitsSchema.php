<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProjectLimits',
    description: 'Resource limits; -1 is unlimited. Each is optional: memory defaults to the server\'s project default, disk to unlimited, the rest to none.',
    properties: [
        new OA\Property(property: 'disk', type: 'integer', example: 10240, description: 'MB'),
        new OA\Property(property: 'memory', type: 'integer', example: 1024, description: 'MB, at least 6, or 256 for a container project; at most memory_budget.max_project_mb in GET /metrics/current'),
        new OA\Property(property: 'cpu', type: 'number', format: 'float', example: 1.5, description: 'CPU cores, fractions allowed'),
        new OA\Property(property: 'device_read_bps', type: 'integer', description: 'Bytes a second'),
        new OA\Property(property: 'device_write_bps', type: 'integer', description: 'Bytes a second'),
        new OA\Property(property: 'bandwidth', type: 'integer', description: 'MB a month. Reported against the month\'s transfer, never enforced'),
        new OA\Property(property: 'mysql_databases', type: 'integer'),
        new OA\Property(property: 'ftp_accounts', type: 'integer'),
        new OA\Property(property: 'sftp_accounts', type: 'integer'),
        new OA\Property(property: 'addon_domains', type: 'integer'),
        new OA\Property(property: 'subdomains', type: 'integer'),
        new OA\Property(property: 'inodes', type: 'integer'),
    ],
    type: 'object',
)]
class ProjectLimitsSchema {}
