<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Requests\Wp\WpLanguageSetRequest;
use App\System\Project\PhpHosting\WordPress\WordPress;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WpLanguageController extends WordPressController
{
    protected function failureField(): string
    {
        return 'language';
    }

    #[OA\Put(
        path: '/projects/{username}/wp/language',
        summary: 'Set the WordPress site language',
        description: 'Installs the language pack when needed and activates it via '
            .'`wp language core install {language} --activate`. Optional `path` selects the install.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['language'],
            properties: [
                new OA\Property(property: 'language', type: 'string', example: 'pl_PL', description: 'Locale code (e.g. pl_PL, de_DE, en_US).'),
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
            new OA\Response(response: 200, description: 'Language set', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'language', type: 'string'),
                    new OA\Property(property: 'message', type: 'string'),
                ])],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'WP-CLI failed, or no WordPress install was found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(string $username, WpLanguageSetRequest $request): JsonResponse
    {
        return $this->respond($username, $request, fn (WordPress $wp, array $validated) => $wp->language()->set(
            $validated['language']
        ));
    }
}
