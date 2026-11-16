<?php

namespace App\Http\Controllers;

use App\Http\Requests\System\SitesDbUpdateRequest;
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

    #[OA\Put(
        path: '/system/sites-db',
        summary: 'Change the site database\'s cache sizes',
        description: 'Writes the sizes into the engine\'s compose .env, as root through the root broker: compose reads '
            .'them on the host, so they cannot live in the engine database. When sites-db is running and a size '
            .'changed, it is recreated with them before the answer (`recreated`): **every hosted PHP site loses its '
            .'database for a few seconds**, so ask the operator first. A field left out stays; null puts it back to '
            .'the engine\'s default. A size is a number and K, M or G, at least the cache\'s minimum and less than '
            .'the server\'s RAM. `pae configure sites-db` does the same. Refused with 403 for a token scoped to some '
            .'projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Sets the cache sizes of the shared MariaDB for hosted PHP sites (e.g. 64M) and restarts it: every hosted PHP site loses its database for a few seconds, so ask the operator first. null restores a default. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'innodb_buffer_pool_size', type: 'string', nullable: true, example: '64M', description: 'At least 8M; default 32M.'),
                new OA\Property(property: 'key_buffer_size', type: 'string', nullable: true, example: '8M', description: 'At least 1M; default 8M.'),
                new OA\Property(property: 'aria_pagecache_buffer_size', type: 'string', nullable: true, example: '8M', description: 'At least 1M; default 8M.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The cache sizes now', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SitesDbCaches'),
                    new OA\Property(property: 'recreated', type: 'boolean', description: 'Whether sites-db was restarted with them; false when nothing changed or it is not running, and reads them when it next starts.'),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(SitesDbUpdateRequest $request): JsonResponse
    {
        $result = $this->caches->set($request->sizes());

        return $this->answer(['recreated' => $result['recreated']]);
    }

    /** @param  array<string, mixed>  $extra */
    private function answer(array $extra = []): JsonResponse
    {
        return new JsonResponse(['data' => $this->caches->toArray(), ...$extra]);
    }
}
