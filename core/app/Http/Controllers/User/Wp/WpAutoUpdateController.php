<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpAutoUpdatePathRequest;
use App\Http\Requests\Wp\WpAutoUpdateSetRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpAutoUpdateController extends WordPressController
{
    protected function failureField(): string
    {
        return 'auto_update';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/auto-update',
        summary: 'Read WordPress auto-update settings',
        description: 'Returns core (`off`|`on`|`minor`), plugins (`off`|`on`), and themes '
            .'(`off`|`on`) auto-update settings, plus each plugin and theme\'s own status '
            .'(`enabled`|`disabled`); `on` means every one is enabled. Optional `path` selects the install.',
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
            new OA\Response(response: 200, description: 'Auto-update settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'core', type: 'string', enum: ['off', 'on', 'minor']),
                    new OA\Property(property: 'plugins', type: 'string', enum: ['off', 'on']),
                    new OA\Property(property: 'themes', type: 'string', enum: ['off', 'on']),
                    new OA\Property(
                        property: 'plugin_items',
                        type: 'object',
                        description: 'Plugin name => enabled|disabled.',
                        additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    ),
                    new OA\Property(
                        property: 'theme_items',
                        type: 'object',
                        description: 'Theme name => enabled|disabled.',
                        additionalProperties: new OA\AdditionalProperties(type: 'string'),
                    ),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username, WpAutoUpdatePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->autoUpdate()->get());
    }

    #[OA\Put(
        path: '/projects/{username}/wp/auto-update',
        summary: 'Set WordPress auto-update settings',
        description: 'Writes any of core (`off`|`on`|`minor`), plugins (`off`|`on`), and themes '
            .'(`off`|`on`); at least one is required and the others are left as they are. Core maps '
            .'to WP_AUTO_UPDATE_CORE; plugins and themes map to enable/disable across that type, '
            .'and succeed when some already had the requested state. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'core', type: 'string', enum: ['off', 'on', 'minor']),
                new OA\Property(property: 'plugins', type: 'string', enum: ['off', 'on']),
                new OA\Property(property: 'themes', type: 'string', enum: ['off', 'on']),
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
            new OA\Response(response: 200, description: 'Auto-update settings updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'core', type: 'string'),
                    new OA\Property(property: 'plugins', type: 'string'),
                    new OA\Property(property: 'themes', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, WpAutoUpdateSetRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->autoUpdate()->set(
            array_intersect_key($validated, array_flip(['core', 'plugins', 'themes']))
        ));
    }
}
