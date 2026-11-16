<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectOverlaySetRequest;
use App\Lib\Overlay\OverlayService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ProjectOverlayController extends Controller
{
    public function __construct(private readonly OverlayService $overlays) {}

    #[OA\Get(
        path: '/projects/{username}/overlay',
        summary: 'Read a project\'s overlay',
        description: 'The project\'s own overlay, whether it shows the global one, the global one, and whether any overlay reaches its pages.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The project\'s overlay', content: new OA\JsonContent(ref: '#/components/schemas/ProjectOverlay')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username): JsonResponse
    {
        return new JsonResponse($this->overlays->projectState($this->projectOr404($username)));
    }

    #[OA\Put(
        path: '/projects/{username}/overlay',
        summary: 'Set a project\'s overlay',
        description: 'Replaces the project\'s overlay settings. `html` is written into every HTML page (text/html answers) of '
            .'every domain of the project, after the global overlay, just before the first </body> (`position: body_end`, '
            .'the default) or </head> (`head_end`); null or omitted means the project has no overlay of its own. '
            .'`show_global: false` keeps the global overlay off this project\'s pages; it defaults to true. Only '
            .'nginx-proxy writes overlays. The project\'s vhosts are re-rendered and the webserver is reloaded in the background.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        x: ['mcp-description' => 'Sets the HTML overlay (a banner, a script) the host proxy writes into every HTML page of the project, before </body> or </head>, and whether the global overlay shows there too. Replaces the previous settings.'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'html', type: 'string', nullable: true, description: 'The project\'s own overlay HTML; null for none.'),
                new OA\Property(property: 'position', type: 'string', enum: ['body_end', 'head_end'], nullable: true, description: 'Defaults to body_end.'),
                new OA\Property(property: 'show_global', type: 'boolean', nullable: true, description: 'Whether the global overlay shows on this project. Defaults to true.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The project\'s overlay', content: new OA\JsonContent(ref: '#/components/schemas/ProjectOverlay')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(string $username, ProjectOverlaySetRequest $request): JsonResponse
    {
        $user = $this->projectOr404($username);
        $this->overlays->setProject($user, $request->overlay(), $request->showGlobal());

        return new JsonResponse($this->overlays->projectState($user));
    }

    #[OA\Delete(
        path: '/projects/{username}/overlay',
        summary: 'Remove a project\'s overlay',
        description: 'Removes the project\'s own overlay and shows the global one on it again. '
            .'The project\'s overlay text goes and is not kept, so it has to be written again; this cannot be '
            .'undone. Ask the operator to confirm first.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The project\'s overlay', content: new OA\JsonContent(ref: '#/components/schemas/ProjectOverlay')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username): JsonResponse
    {
        $user = $this->projectOr404($username);
        $this->overlays->removeProject($user);

        return new JsonResponse($this->overlays->projectState($user));
    }
}
