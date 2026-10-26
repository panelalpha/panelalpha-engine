<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpCoreDownloadRequest;
use App\Http\Requests\Wp\WpCoreInstallRequest;
use App\Http\Requests\Wp\WpCorePathRequest;
use App\Http\Requests\Wp\WpCoreUpdateRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpCoreController extends WordPressController
{
    protected function failureField(): string
    {
        return 'core';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/core',
        summary: 'Read WordPress core status',
        description: 'Returns whether WordPress core is installed, which version the install '
            .'reports, and `state`, which tells the reasons it is not installed apart: '
            .'`installed`; `not_installed` (core, wp-config.php and the database are fine, '
            .'the WordPress tables are missing); `no_config` (core files, no wp-config.php); '
            .'`db_unreachable` (wp-config.php present, the database it names cannot be reached); '
            .'`no_core` (no WordPress here, version is null); `unknown` (not installed, for a '
            .'reason the engine cannot tell). A site that fails to load (a fatal error) is a 422, '
            .'not installed=false; pass skip_plugins to check past a broken plugin. Optional '
            .'`path` selects the install; omitted, the main domain document root is used.',
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
        ],
        responses: [
            new OA\Response(response: 200, description: 'Core status', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'installed', type: 'boolean'),
                    new OA\Property(property: 'version', type: 'string', nullable: true, description: 'From wp-includes/version.php; null when there are no core files.'),
                    new OA\Property(
                        property: 'state',
                        type: 'string',
                        enum: ['installed', 'not_installed', 'no_config', 'db_unreachable', 'no_core', 'unknown'],
                        description: 'installed: WordPress is installed. not_installed: core files, wp-config.php and '
                            .'a reachable database, but WordPress was never installed into it. no_config: core '
                            .'files without a wp-config.php. db_unreachable: wp-config.php is there but the MySQL '
                            .'client cannot connect to the database it names (`wp db query` fails with a client '
                            .'error such as ERROR 2005 or ERROR 1045). no_core: no WordPress core files at the path, or, '
                            .'on a project in its own containers, no running container with PHP and WordPress. '
                            .'unknown: not installed, for a reason the engine cannot tell, for example output it '
                            .'does not recognise, or no MySQL client to probe the database with.',
                    ),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, the site fails to load, or the project is not running', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function status(string $username, WpCorePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->core()->status());
    }

    #[OA\Get(
        path: '/projects/{username}/wp/core/updates',
        summary: 'List available WordPress core updates',
        description: 'Lists core versions newer than the installed one (WP-CLI `core check-update`); '
            .'an empty list when WordPress is up to date. Optional `path` selects the install; '
            .'omitted, the main domain document root is used.',
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
        ],
        responses: [
            new OA\Response(response: 200, description: 'Available core updates', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'string'),
                    new OA\Property(property: 'update_type', type: 'string', example: 'minor'),
                    new OA\Property(property: 'package_url', type: 'string'),
                ]))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updates(string $username, WpCorePathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp) => $wp->core()->checkUpdate());
    }

    #[OA\Post(
        path: '/projects/{username}/wp/core/download',
        summary: 'Download WordPress core files',
        description: 'Runs `wp core download` into the install directory of a PHP hosting project, the first '
            .'step of a new install: then create the database, `wp/config`, `wp/core/install` and '
            .'`wp/language`. A directory that already holds WordPress core (wp-includes/version.php) '
            .'is refused with 409 and never overwritten. A project running in its own containers is '
            .'refused with 422: its WordPress comes from its image. Optional `path` selects the '
            .'directory; omitted, the main domain document root is used.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'locale',
                    type: 'string',
                    nullable: true,
                    pattern: '^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$',
                    example: 'pl_PL',
                    description: 'WordPress language to download. Omitted: en_US.',
                ),
                new OA\Property(
                    property: 'version',
                    type: 'string',
                    nullable: true,
                    pattern: '^(latest|\d+\.\d+(\.\d+)?)$',
                    example: '6.7.1',
                    description: 'WordPress version, or `latest`. Omitted: the latest release.',
                ),
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'Directory to download into. Omitted: the main domain document root.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Core downloaded', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'string', nullable: true, example: '6.7.1'),
                    new OA\Property(property: 'locale', type: 'string', example: 'pl_PL'),
                    new OA\Property(property: 'path', type: 'string', example: '/home/alice/example.test/public_html'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'WordPress core is already in the directory', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, invalid locale or version, or a project running in its own containers', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function download(string $username, WpCoreDownloadRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->core()->download(
            self::optional($validated['locale'] ?? null),
            self::optional($validated['version'] ?? null),
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/core/install',
        summary: 'Install WordPress core',
        description: 'Runs `wp core install` with the site URL, title, admin user, admin email, '
            .'and admin password. Optional `path` selects the document root to install into.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url', 'title', 'admin_user', 'admin_email', 'admin_password'],
            properties: [
                new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://example.test'),
                new OA\Property(property: 'title', type: 'string', example: 'Example Site'),
                new OA\Property(property: 'admin_user', type: 'string', example: 'admin'),
                new OA\Property(property: 'admin_email', type: 'string', format: 'email', example: 'admin@example.com'),
                new OA\Property(property: 'admin_password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'skip_email', type: 'boolean', description: 'Do not email the admin about the new site. Omitted: false.'),
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
            new OA\Response(response: 200, description: 'Core installed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function install(string $username, WpCoreInstallRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->core()->install(
            [
                'url' => $validated['url'],
                'title' => $validated['title'],
                'admin_user' => $validated['admin_user'],
                'admin_email' => $validated['admin_email'],
                'admin_password' => $validated['admin_password'],
                'skip_email' => (bool) ($validated['skip_email'] ?? false),
            ]
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/core/update',
        summary: 'Update WordPress core to a target version',
        description: 'Runs `wp core update --version=` with the given target version. Optional '
            .'`path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['version'],
            properties: [
                new OA\Property(property: 'version', type: 'string', example: '6.7.1'),
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
            new OA\Response(response: 200, description: 'Core updated', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'version', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, WpCoreUpdateRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->core()->update(
            $validated['version']
        ));
    }
}
