<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpMaintenancePathRequest;
use App\Http\Requests\Wp\WpMaintenanceSetRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpMaintenanceController extends WordPressController
{
    protected function failureField(): string
    {
        return 'maintenance';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/maintenance',
        summary: 'Read WordPress maintenance mode',
        description: 'Returns whether maintenance mode is enabled on the project\'s WordPress '
            .'install. Optional `path` selects the install; omitted, the main domain document '
            .'root is used.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'path',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
                description: 'WordPress directory. On PHP hosting it is inside the project home, read as the file manager reads a path: absolute, or relative to the home. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
            ),
            new OA\Parameter(
                name: 'service',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
                description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
            ),
            new OA\Parameter(
                name: 'skip_plugins',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean'),
                description: 'Run without loading plugins and themes (--skip-plugins --skip-themes), so a plugin or theme with a fatal error does not block the operation. Omitted: false.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Maintenance mode status', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username, WpMaintenancePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->maintenance()->get());
    }

    #[OA\Put(
        path: '/projects/{username}/wp/maintenance',
        summary: 'Set WordPress maintenance mode',
        description: 'Turns maintenance mode on or off via `wp maintenance-mode activate|deactivate`. '
            .'Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['enabled'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
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
            new OA\Response(response: 200, description: 'Maintenance mode updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'enabled', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, WpMaintenanceSetRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->maintenance()->set(
            (bool) $validated['enabled']
        ));
    }
}
