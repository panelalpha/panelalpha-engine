<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpThemeGetRequest;
use App\Http\Requests\Wp\WpThemeInstallRequest;
use App\Http\Requests\Wp\WpThemeListRequest;
use App\Http\Requests\Wp\WpThemePathRequest;
use App\Http\Requests\Wp\WpThemeSearchRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpThemeController extends WordPressController
{
    protected function failureField(): string
    {
        return 'theme';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/themes',
        summary: 'List installed WordPress themes',
        description: 'Lists themes on the project\'s WordPress install. Optional `path` selects the '
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
                name: 'fields',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'name,status,update,update_version,version,title,description'),
                description: 'Comma-separated theme fields to return. Omitted: name, status, update, version, update_version and auto_update.',
            ),
            new OA\Parameter(
                name: 'status',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'active,inactive'),
                description: 'Comma-separated statuses to list: active, parent, inactive. Omitted: all.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Installed themes', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username, WpThemeListRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->themes()->list(
            $validated['fields'] ?? null,
            $validated['status'] ?? null,
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/wp/themes/search',
        summary: 'Search wordpress.org for themes',
        description: 'Searches the public wordpress.org theme directory via WP-CLI. Optional `path` '
            .'selects the install used to run the search.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'term', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Search term.'),
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
                name: 'fields',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'name,slug,version,rating,screenshot_url,preview_url,description'),
                description: 'Comma-separated wordpress.org fields to return. Omitted: name, slug and rating.',
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100),
                description: 'Results to return. Omitted: 10.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Search results', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function search(string $username, WpThemeSearchRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->themes()->search(
            $validated['term'],
            $validated['fields'] ?? null,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null,
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/wp/themes/{theme}',
        summary: 'Read one installed WordPress theme',
        description: 'Returns one installed theme\'s details (WP-CLI `theme get`). A theme that is '
            .'not installed is a 404. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Theme slug.'),
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
                name: 'fields',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'name,title,status,version'),
                description: 'Comma-separated theme fields to return. Omitted: name, title, version, status, parent_theme, template_dir, stylesheet_dir, template, stylesheet, screenshot, description, author, tags, theme_root and theme_root_uri.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Theme details', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found, or the theme is not installed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username, string $theme, WpThemeGetRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->themes()->get(
            $theme,
            $validated['fields'] ?? null,
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/wp/themes/{theme}/installed',
        summary: 'Check whether a WordPress theme is installed',
        description: 'Answers installed true or false (WP-CLI `theme is-installed`). A site that fails '
            .'to load is a 422, not installed=false; pass skip_plugins to check past a broken plugin. '
            .'Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Theme slug.'),
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
            new OA\Response(response: 200, description: 'Whether the theme is installed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'theme', type: 'string'),
                    new OA\Property(property: 'installed', type: 'boolean'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function installed(string $username, string $theme, WpThemePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->themes()->isInstalled($theme));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/themes/install',
        summary: 'Install a WordPress theme',
        description: 'Installs a theme by slug. Optional `force` reinstalls over an existing copy. '
            .'Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['slug'],
            properties: [
                new OA\Property(property: 'slug', type: 'string', example: 'twentytwentyfour'),
                new OA\Property(property: 'force', type: 'boolean', description: 'Reinstall over an existing copy.'),
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
            new OA\Response(response: 200, description: 'Theme installed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'theme', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function install(string $username, WpThemeInstallRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->themes()->install(
            $validated['slug'],
            (bool) ($validated['force'] ?? false)
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/themes/{theme}/activate',
        summary: 'Activate a WordPress theme',
        description: 'Activates an installed theme by slug. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Theme slug.'),
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
            new OA\Response(response: 200, description: 'Theme activated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function activate(string $username, string $theme, WpThemePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->themes()->activate($theme));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/themes/{theme}/update',
        summary: 'Update a WordPress theme',
        description: 'Updates an installed theme by slug. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Theme slug.'),
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
            new OA\Response(response: 200, description: 'Theme updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, string $theme, WpThemePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->themes()->update($theme));
    }

    #[OA\Delete(
        path: '/projects/{username}/wp/themes/{theme}',
        summary: 'Delete a WordPress theme',
        description: 'Deletes an installed theme by slug. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Theme slug.'),
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
            new OA\Response(response: 200, description: 'Theme deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $theme, WpThemePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->themes()->delete($theme));
    }
}
