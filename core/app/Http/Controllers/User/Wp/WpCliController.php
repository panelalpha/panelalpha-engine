<?php

namespace App\Http\Controllers\User\Wp;

use App\Http\Controllers\Controller;
use App\Http\Requests\WpCliCommandRunRequest;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class WpCliController extends Controller
{
    #[OA\Post(
        path: '/projects/{username}/wp/command',
        summary: 'Run a WP-CLI command',
        description: 'Runs a raw WP-CLI argument list against the project\'s WordPress install. '
            .'Optional `path` selects the install; omitted, the main domain document root is used. '
            .'On a project running in its own containers, `path` is as the container sees it, and omitted, '
            .'the engine finds the container with PHP and WordPress itself. '
            .'If `--path=` is also present inside `args`, that value wins. Synchronous: the body is '
            .'exit_code, stdout, and stderr.',
        security: [['bearerAuth' => []]],
        tags: ['WP-CLI'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['args'],
            properties: [
                new OA\Property(property: 'args', type: 'array', items: new OA\Items(type: 'string'), example: ['plugin', 'list', '--format=json']),
                new OA\Property(
                    property: 'path',
                    type: 'string',
                    nullable: true,
                    description: 'WordPress directory. Omitted: the main domain document root, or, on a project running in its own containers, the install the engine finds there. On such a project it is the directory as the container sees it, absolute or relative to its working directory.',
                    example: '/home/demo/demo.example/public_html',
                ),
                new OA\Property(
                    property: 'service',
                    type: 'string',
                    nullable: true,
                    description: 'Compose service whose container runs WordPress, on a project running in its own containers. Omitted: the first container with both PHP and WordPress.',
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'WP-CLI output', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'exit_code', type: 'integer', example: 0),
                    new OA\Property(property: 'stdout', type: 'string'),
                    new OA\Property(property: 'stderr', type: 'string'),
                ],
            )),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'No WordPress install was found, or invalid input', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    /**
     * @param  string  $username
     * @return JsonResponse
     */
    public function run($username, WpCliCommandRunRequest $request)
    {
        $user = $this->projectOr404($username);

        $validated = $request->validated();
        /** @var list<string> $args */
        $args = array_values($validated['args']);
        $path = $validated['path'] ?? null;
        $path = is_string($path) && $path !== '' ? $path : null;
        $service = $validated['service'] ?? null;
        $service = is_string($service) && $service !== '' ? $service : null;

        try {
            $result = WpCli::for($user, $path, service: $service)->run($args);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'args' => $e->getMessage(),
            ]);
        }

        return new JsonResponse($result);
    }
}
