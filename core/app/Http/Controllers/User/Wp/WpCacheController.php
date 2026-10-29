<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpCacheFlushRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpCacheController extends WordPressController
{
    protected function failureField(): string
    {
        return 'cache';
    }

    #[OA\Post(
        path: '/projects/{username}/wp/cache/flush',
        summary: 'Flush the WordPress object and page caches',
        description: 'Runs `wp cache flush` on the project\'s WordPress install, then, best effort, '
            .'purges the page caches of WP Super Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket '
            .'and WP Fastest Cache; `page_caches` reports flushed, failed (logged, not an error) or '
            .'skipped (with skip_plugins there is no plugin to call). On OpenLiteSpeed, a successful '
            .'flush schedules the same webserver reload the raw command schedules. Optional `path` '
            .'selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. On PHP hosting it is inside the project home, read as the file manager reads a path: absolute, or relative to the home. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
                ),
                new OA\Property(
                    property: 'service',
                    type: 'string',
                    nullable: true,
                    description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
                ),
                new OA\Property(
                    property: 'skip_plugins',
                    type: 'boolean',
                    description: 'Run without loading plugins and themes (--skip-plugins --skip-themes), so a plugin or theme with a fatal error does not block the operation. Omitted: false.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Cache flushed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'page_caches', type: 'string', enum: ['flushed', 'failed', 'skipped']),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function flush(string $username, WpCacheFlushRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->cache()->flush());
    }
}
