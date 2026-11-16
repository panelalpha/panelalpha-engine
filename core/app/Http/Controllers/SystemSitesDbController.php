<?php

namespace App\Http\Controllers;

use App\Support\SitesDbCaches;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The cache sizes of sites-db, the MariaDB of hosted PHP sites: what `pae configure sites-db` changes. A token
 * scoped to some projects is refused, as on every global route.
 */
class SystemSitesDbController extends Controller
{
    public function __construct(private readonly SitesDbCaches $caches) {}

    #[OA\Get(
        path: '/system/sites-db',
        summary: 'Read the site database\'s cache sizes',
        description: 'The InnoDB buffer pool, MyISAM key buffer and Aria page cache of sites-db: the size set (or the '
            .'engine\'s default), and what the running mariadbd uses in MB, null while sites-db is not running. '
            .'Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Reads the cache sizes of the shared MariaDB for hosted PHP sites, set and running. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The cache sizes', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SitesDbCaches')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['data' => $this->caches->toArray()]);
    }
}
