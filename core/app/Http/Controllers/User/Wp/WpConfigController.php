<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpConfigCreateRequest;
use App\Http\Requests\Wp\WpConfigPathRequest;
use App\Http\Requests\Wp\WpConfigSetRequest;
use App\System\Project\PhpHosting\WordPress\Config;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpConfigController extends WordPressController
{
    private const VALUE_LIMIT = 'A value of more than '.Config::MAX_VALUE_LENGTH.' bytes is refused with a '
        .'422 naming that limit, and nothing is written: WP-CLI cannot reliably write or read back a '
        .'longer wp-config.php entry.';

    protected function failureField(): string
    {
        return 'config';
    }

    #[OA\Get(
        path: '/projects/{username}/wp/config',
        summary: 'List WordPress wp-config constants',
        description: 'Lists constants defined in wp-config.php on the project\'s WordPress '
            .'install; `type=variable` or `type=all` lists variables such as $table_prefix too. '
            .'Read without loading WordPress. Optional `path` selects the install; omitted, the '
            .'main domain document root is used.',
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
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['constant', 'variable', 'all']),
                description: 'Which wp-config entries to list. Omitted: constant.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'wp-config entries (name, value, type)', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username, WpConfigPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->config()->list(
            $validated['type'] ?? 'constant'
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/config',
        summary: 'Create wp-config.php',
        description: 'Writes a new wp-config.php from database credentials (WP-CLI `config create`), '
            .'e.g. after downloading core. An existing wp-config.php is a 422 unless `force`. WP-CLI '
            .'first connects to the database unless `skip_check`. Values reach WP-CLI untrimmed. '
            .self::VALUE_LIMIT.' '
            .'Optional `path` selects the install; omitted, the main domain document root is used.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['dbname', 'dbuser'],
            properties: [
                new OA\Property(property: 'dbname', type: 'string'),
                new OA\Property(property: 'dbuser', type: 'string'),
                new OA\Property(property: 'dbpass', type: 'string', description: 'Omitted: no password.'),
                new OA\Property(
                    property: 'dbhost',
                    type: 'string',
                    description: 'Omitted: on PHP hosting, the projects\' MySQL server, as GET /projects/{username}/mysql/server-info reports it; '
                        .'on a project running in its own containers, the host its wp-config.php already names, such as the compose database service, '
                        .'else localhost.',
                ),
                new OA\Property(property: 'dbprefix', type: 'string', description: 'Table prefix. Omitted: wp_.'),
                new OA\Property(property: 'dbcharset', type: 'string', description: 'Omitted: utf8.'),
                new OA\Property(property: 'dbcollate', type: 'string', description: 'Omitted: empty.'),
                new OA\Property(property: 'locale', type: 'string', example: 'en_US', description: 'WPLANG. Omitted: none.'),
                new OA\Property(property: 'force', type: 'boolean', description: 'Overwrite an existing wp-config.php.'),
                new OA\Property(property: 'skip_check', type: 'boolean', description: 'Do not check the database connection first.'),
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
            new OA\Response(response: 200, description: 'wp-config.php created', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, wp-config.php exists, the database check failed, or a value is longer than the limit', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(string $username, WpConfigCreateRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->config()->create(
            array_intersect_key($validated, array_flip([
                'dbname', 'dbuser', 'dbpass', 'dbhost', 'dbprefix', 'dbcharset', 'dbcollate', 'locale', 'force', 'skip_check',
            ])),
        ));
    }

    #[OA\Get(
        path: '/projects/{username}/wp/config/{constant}',
        summary: 'Read a WordPress wp-config constant',
        description: 'Reads one constant (or, with `type`, a variable) from wp-config.php via `wp config '
            .'get`, read as JSON so the value keeps the type wp-config.php gives it: a string is a string, true '
            .'and false are booleans, a number is a number, an array is a JSON array. An entry that is not defined '
            .'is a 422 whose message says it is not defined. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'constant', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Constant name.'),
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
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['constant', 'variable', 'all']),
                description: 'Look the name up as a constant, a variable, or either. Omitted: constant.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Constant value', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'constant', type: 'string'),
                    new OA\Property(property: 'value', description: 'The value as wp-config.php defines it: a string, a boolean, a number, an array or null.'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username, string $constant, WpConfigPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->config()->get(
            $constant,
            $validated['type'] ?? 'constant',
        ));
    }

    #[OA\Put(
        path: '/projects/{username}/wp/config/{constant}',
        summary: 'Set a WordPress wp-config constant',
        description: 'Sets a constant (or, with `type`, a variable) in wp-config.php via `wp config '
            .'set`. The value is stored as sent, an empty string and surrounding whitespace '
            .'included. Pass `raw=true` for unquoted PHP values such as true/false. '
            .self::VALUE_LIMIT.' Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'constant', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Constant name.'),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['value'],
            properties: [
                new OA\Property(property: 'value', type: 'string', example: 'true'),
                new OA\Property(property: 'raw', type: 'boolean', description: 'Place the value as raw PHP instead of a quoted string.'),
                new OA\Property(
                    property: 'type',
                    type: 'string',
                    enum: ['constant', 'variable', 'all'],
                    description: 'constant or variable; all updates whichever exists and adds a constant otherwise. Omitted: constant.',
                ),
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
            new OA\Response(response: 200, description: 'Constant set', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'constant', type: 'string'),
                    new OA\Property(property: 'value', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, a value is longer than the limit, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, string $constant, WpConfigSetRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->config()->set(
            $constant,
            (string) $validated['value'],
            (bool) ($validated['raw'] ?? false),
            $validated['type'] ?? 'constant',
        ));
    }

    #[OA\Delete(
        path: '/projects/{username}/wp/config/{constant}',
        summary: 'Delete a WordPress wp-config constant',
        description: 'Removes a constant (or, with `type`, a variable) from wp-config.php via `wp '
            .'config delete`. Optional `path` selects the install. '
            .'The entry is removed from wp-config.php and its value is not kept anywhere; this cannot be undone. '
            .'Ask the operator to confirm first.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'constant', in: 'path', required: true, schema: new OA\Schema(type: 'string'), description: 'Constant name.'),
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
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['constant', 'variable', 'all']),
                description: 'Remove a constant, a variable, or either. Omitted: constant.',
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Constant deleted', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'constant', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $constant, WpConfigPathRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->config()->delete(
            $constant,
            $validated['type'] ?? 'constant',
        ));
    }
}
