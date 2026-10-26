<?php

namespace App\Http\Controllers;

use App\System;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PhpController extends Controller
{
    #[OA\Get(
        path: '/php/available-versions',
        summary: 'List available PHP versions on the server',
        security: [['bearerAuth' => []]],
        tags: ['PHP'],
        responses: [
            new OA\Response(response: 200, description: 'Available PHP versions', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string', example: '8.2'))],
            )),
        ],
    )]
    public function listAvailableVersions(): JsonResponse
    {
        $system = new System;

        return new JsonResponse([
            'data' => $system->php()->listAvailablePhpVersions(),
        ]);
    }
}
