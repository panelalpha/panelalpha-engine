<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpDatabaseExportRequest;
use App\Http\Requests\Wp\WpDatabaseImportRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpDatabaseController extends WordPressController
{
    protected function failureField(): string
    {
        return 'database';
    }

    #[OA\Post(
        path: '/projects/{username}/wp/database/export',
        summary: 'Export the WordPress install database to a file',
        description: 'Runs `wp db export {file}` against the install\'s own database (wp-config '
            .'credentials and table prefix), not the engine MySQL account API. '
            .'`file` is a path inside the project. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['file'],
            properties: [
                new OA\Property(
                    property: 'file',
                    type: 'string',
                    example: '/home/alice/example.test/public_html/backup.sql',
                    description: 'Destination SQL file path inside the project.',
                ),
                new OA\Property(
                    property: 'tables',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    example: ['wp_posts', 'wp_postmeta'],
                    description: 'Only these tables. Omitted: every table of the install.',
                ),
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
            new OA\Response(response: 200, description: 'Database exported', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'file', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function export(string $username, WpDatabaseExportRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->database()->export(
            $validated['file'],
            array_values($validated['tables'] ?? []),
        ));
    }

    #[OA\Post(
        path: '/projects/{username}/wp/database/import',
        summary: 'Import a SQL file into the WordPress install database',
        description: 'Strips DEFINER clauses from the SQL file when needed, then runs '
            .'`wp db import {file}` against the install\'s own database. Neither step loads '
            .'WordPress, so an empty database or a broken plugin does not block the import. '
            .'`file` is a path inside the project. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['file'],
            properties: [
                new OA\Property(
                    property: 'file',
                    type: 'string',
                    example: '/home/alice/example.test/public_html/backup.sql',
                    description: 'Source SQL file path inside the project.',
                ),
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
            new OA\Response(response: 200, description: 'Database imported', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'file', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function import(string $username, WpDatabaseImportRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->database()->import(
            $validated['file']
        ));
    }
}
