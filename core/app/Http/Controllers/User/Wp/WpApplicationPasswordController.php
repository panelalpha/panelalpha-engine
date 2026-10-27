<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpApplicationPasswordCreateRequest;
use App\Http\Requests\Wp\WpApplicationPasswordPathRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpApplicationPasswordController extends WordPressController
{
    protected function failureField(): string
    {
        return 'application_password';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/users/{user}/application-passwords',
        description: 'Lists application passwords for a WordPress user on the project\'s WordPress install. '
            .'Optional `path` selects the install; omitted, the main domain document root is used.',
        summary: 'List application passwords for a WordPress user',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
            new OA\Parameter(
                name: 'path',
                description: 'WordPress directory. On PHP hosting it is inside the project home, read as the file manager reads a path: absolute, or relative to the home. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'service',
                description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
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
            new OA\Response(response: 200, description: 'Application passwords', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username, string $user, WpApplicationPasswordPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->applicationPasswords()->list(
            $user
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/users/{user}/application-passwords',
        description: 'Creates an application password. The plaintext secret is returned once in this '
            .'response only. Optional `path` selects the install.',
        summary: 'Create an application password for a WordPress user',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'myapp', description: 'Unique application name.'),
                new OA\Property(property: 'app_id', type: 'string', nullable: true, description: 'Optional application ID to attribute to the password.'),
                new OA\Property(
                    property: 'path',
                    description: 'WordPress directory. On PHP hosting it is inside the project home, read as the file manager reads a path: absolute, or relative to the home. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
                    type: 'string',
                    nullable: true,
                ),
                new OA\Property(
                    property: 'service',
                    description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
                    type: 'string',
                    nullable: true,
                ),
                new OA\Property(
                    property: 'skip_plugins',
                    type: 'boolean',
                    description: 'Run without loading plugins and themes (--skip-plugins --skip-themes), so a plugin or theme with a fatal error does not block the operation. Omitted: false.',
                ),
            ],
        )),
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Application password created (secret once)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'password', type: 'string', description: 'One-time plaintext secret; not shown again.'),
                ], type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(string $username, string $user, WpApplicationPasswordCreateRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->applicationPasswords()->create(
            $user,
            $validated['name'],
            self::optional($validated['app_id'] ?? null)
        ));
    }

    #[OA\Delete(
        path: '/projects/{username}/wp/users/{user}/application-passwords/{uuid}',
        description: 'Deletes an application password by UUID. Optional `path` selects the install.',
        summary: 'Delete an application password for a WordPress user',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Application password UUID.'),
            new OA\Parameter(
                name: 'path',
                description: 'WordPress directory. On PHP hosting it is inside the project home, read as the file manager reads a path: absolute, or relative to the home. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
            ),
            new OA\Parameter(
                name: 'service',
                description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
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
            new OA\Response(response: 200, description: 'Application password deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $user, string $uuid, WpApplicationPasswordPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->applicationPasswords()->delete(
            $user,
            $uuid
        ));
    }
}
