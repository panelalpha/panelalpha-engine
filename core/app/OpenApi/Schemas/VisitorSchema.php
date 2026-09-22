<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VisitorOverview',
    required: ['unique', 'total', 'visits', 'visits_length'],
    properties: [
        new OA\Property(property: 'unique', type: 'integer', example: 10, description: 'Unique hosts for overlapping calendar months (GENERAL TotalUnique), not unique-in-range'),
        new OA\Property(property: 'total', type: 'integer', example: 63, description: 'Hits in the requested start/end day range'),
        new OA\Property(
            property: 'visits',
            properties: [
                new OA\Property(
                    property: 'records',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'integer'),
                    example: ['2026-09-01' => 5],
                ),
                new OA\Property(property: 'total', type: 'integer', example: 18, description: 'Sum of visits.records in the requested start/end day range'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'visits_length',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'integer'),
            description: 'SESSION histogram for overlapping calendar months',
            example: ['0s-30s' => 8],
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VisitorBreakdownItem',
    required: ['label', 'visits'],
    properties: [
        new OA\Property(property: 'label', type: 'string', example: 'direct'),
        new OA\Property(property: 'visits', type: 'integer', example: 30, description: 'Hits or visits from the monthly AWStats section; not clipped to the day range'),
        new OA\Property(property: 'code', type: 'string', example: 'direct'),
    ],
    type: 'object',
)]
class VisitorSchema
{
}
