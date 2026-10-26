<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpSearchReplaceRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpSearchReplaceController extends WordPressController
{
    protected function failureField(): string
    {
        return 'search_replace';
    }

    #[OA\Post(
        path: '/projects/{username}/wp/search-replace',
        summary: 'Search-replace a string in the WordPress install',
        description: 'Runs `wp search-replace {search} {replacement}` with an optional list of '
            .'extra WP-CLI arguments (tables, `--dry-run`, `--skip-columns=…`, and so on). '
            .'Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['search', 'replacement'],
            properties: [
                new OA\Property(property: 'search', type: 'string', example: 'http://example.test'),
                new OA\Property(property: 'replacement', type: 'string', example: 'https://example.com', description: 'Used as sent; an empty string removes the search string.'),
                new OA\Property(
                    property: 'args',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'Extra WP-CLI arguments after search and replacement (e.g. --dry-run, table names).',
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
            new OA\Response(response: 200, description: 'Search-replace completed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'search', type: 'string'),
                    new OA\Property(property: 'replacement', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function run(string $username, WpSearchReplaceRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->searchReplace()->run(
            $validated['search'],
            $validated['replacement'],
            array_values($validated['args'] ?? [])
        ));
    }
}
