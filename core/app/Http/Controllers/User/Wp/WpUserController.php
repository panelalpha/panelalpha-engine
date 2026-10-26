<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpUserCreateRequest;
use App\Http\Requests\Wp\WpUserListRequest;
use App\Http\Requests\Wp\WpUserPasswordRequest;
use App\Http\Requests\Wp\WpUserPathRequest;
use App\Http\Requests\Wp\WpUserUpdateRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpUserController extends WordPressController
{
    protected function failureField(): string
    {
        return 'user';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/users',
        summary: 'List WordPress users',
        description: 'Lists users on the project\'s WordPress install. Optional `path` selects the '
            .'install; omitted, the main domain document root is used.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(
                name: 'path',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
                description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Parameter(
                name: 'role',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'administrator'),
                description: 'Only users with this role. Omitted: all users.',
            ),
            new OA\Parameter(
                name: 'fields',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'id,user_login,display_name,user_email,user_registered,roles'),
                description: 'Comma-separated user fields to return. Omitted: ID, user_login, display_name, user_email, user_registered and roles.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'WordPress users', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username, WpUserListRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->users()->list(
            $validated['role'] ?? null,
            $validated['fields'] ?? null,
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/users',
        summary: 'Create a WordPress user',
        description: 'Creates a WordPress user. The caller provides the password; there is no named '
            .'operation that generates and prints one. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['login', 'email', 'password'],
            properties: [
                new OA\Property(property: 'login', type: 'string', example: 'jdoe'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jdoe@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'role', type: 'string', example: 'editor'),
                new OA\Property(property: 'display_name', type: 'string'),
                new OA\Property(property: 'first_name', type: 'string'),
                new OA\Property(property: 'last_name', type: 'string'),
                new OA\Property(property: 'send_email', type: 'boolean', description: 'Email the new user their account details. Omitted: false.'),
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Response(response: 200, description: 'User created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'user', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(string $username, WpUserCreateRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->users()->create(
            [
                'login' => $validated['login'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'] ?? null,
                'display_name' => $validated['display_name'] ?? null,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'send_email' => (bool) ($validated['send_email'] ?? false),
            ]
        ));
    }

    #[OA\Put(
        path: '/projects/{username}/wp/users/{user}',
        summary: 'Update a WordPress user',
        description: 'Updates fields on a WordPress user (login or ID). An optional `password` is set '
            .'in the same update, so the profile and the password change together or not at all. '
            .'Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'role', type: 'string'),
                new OA\Property(property: 'display_name', type: 'string'),
                new OA\Property(property: 'first_name', type: 'string'),
                new OA\Property(property: 'last_name', type: 'string'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, description: 'New password. Omitted: unchanged.'),
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Response(response: 200, description: 'User updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, string $user, WpUserUpdateRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->users()->update(
            $user,
            [
                'email' => $validated['email'] ?? null,
                'role' => $validated['role'] ?? null,
                'display_name' => $validated['display_name'] ?? null,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'password' => $validated['password'] ?? null,
            ]
        ));
    }

    #[OA\Delete(
        path: '/projects/{username}/wp/users/{user}',
        summary: 'Delete a WordPress user',
        description: 'Deletes a WordPress user by login or ID. Optional `reassign` moves their '
            .'content to another user. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
            new OA\Parameter(
                name: 'reassign',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
                description: 'WordPress user login or ID to reassign content to.',
            ),
            new OA\Parameter(
                name: 'path',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string'),
                description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Response(response: 200, description: 'User deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $user, WpUserPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->users()->delete(
            $user,
            self::optional($validated['reassign'] ?? null)
        ));
    }

    #[OA\Put(
        path: '/projects/{username}/wp/users/{user}/password',
        summary: 'Set a WordPress user password',
        description: 'Sets the password to a value the caller provides. There is no named operation '
            .'that generates a password and prints it. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Response(response: 200, description: 'Password set', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function setPassword(string $username, string $user, WpUserPasswordRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->users()->setPassword(
            $user,
            $validated['password']
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/users/{user}/sso',
        summary: 'Create a one-time SSO URL for a WordPress user',
        description: 'Ensures the engine must-use plugin is on the install, then returns a one-time '
            .'URL of the form `{siteurl}/?panelalpha_sso={token}`. Does not install the panel '
            .'connector. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'WordPress user login or ID.'),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
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
            new OA\Response(response: 200, description: 'One-time SSO URL', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'url', type: 'string', format: 'uri'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function createSso(string $username, string $user, WpUserPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->sso()->create(
            $user
        ));
    }
}
