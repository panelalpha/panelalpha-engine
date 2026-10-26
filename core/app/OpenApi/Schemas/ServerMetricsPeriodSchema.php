<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ServerMetricsPeriod',
    description: 'Averages over one bucket. A bucket with no samples carries only period.',
    properties: [
        new OA\Property(property: 'period', type: 'string', example: '2026-09-28 12:00:00', description: 'Bucket start, UTC'),
        new OA\Property(property: 'cpu_percent', type: 'number', format: 'float', example: 12.5),
        new OA\Property(property: 'cpu_load_avg_1', type: 'number', format: 'float'),
        new OA\Property(property: 'cpu_load_avg_5', type: 'number', format: 'float'),
        new OA\Property(property: 'cpu_load_avg_15', type: 'number', format: 'float'),
        new OA\Property(property: 'ram_percent', type: 'number', format: 'float', example: 45.2),
        new OA\Property(property: 'swap_percent', type: 'number', format: 'float'),
        new OA\Property(property: 'disk_percent', type: 'number', format: 'float', nullable: true, example: 68.0, description: 'Disk holding /home. Only from GET /metrics/history; null before it was recorded'),
        new OA\Property(property: 'disk_read_bps', type: 'number', format: 'float', description: 'Bytes per second'),
        new OA\Property(property: 'disk_write_bps', type: 'number', format: 'float', description: 'Bytes per second'),
        new OA\Property(property: 'disk_read_iops', type: 'number', format: 'float'),
        new OA\Property(property: 'disk_write_iops', type: 'number', format: 'float'),
        new OA\Property(property: 'net_in_bps', type: 'number', format: 'float', description: 'Bytes per second'),
        new OA\Property(property: 'net_out_bps', type: 'number', format: 'float', description: 'Bytes per second'),
        new OA\Property(property: 'net_in_pps', type: 'number', format: 'float'),
        new OA\Property(property: 'net_out_pps', type: 'number', format: 'float'),
    ],
    type: 'object',
)]
class ServerMetricsPeriodSchema {}
