<?php

namespace App\Http\Controllers;

use App\Http\Requests\OverlaySetRequest;
use App\Lib\Overlay\OverlayService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The overlay the host proxy writes into the HTML pages of every project.
 */
class SystemOverlayController extends Controller
{
    public function __construct(private readonly OverlayService $overlays) {}

    #[OA\Get(
        path: '/system/overlay',
        summary: 'Read the global overlay',
        description: 'The HTML the host proxy writes into the HTML pages of every project that does not hide it, or null when there is none.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'The global overlay', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'overlay', ref: '#/components/schemas/Overlay', nullable: true)],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    #[OA\Put(
        path: '/system/overlay',
        summary: 'Set the global overlay',
        description: 'Writes `html` into every HTML page (text/html answers) of every project served by nginx-proxy, just before '
            .'the first </body> (`position: body_end`, the default) or </head> (`head_end`). A project can hide it with '
            .'PUT /projects/{username}/overlay `show_global: false`; a project\'s own overlay is written after it. Pages '
            .'without that tag, and answers the app compresses whatever it is asked, are passed on unchanged. Every '
            .'project\'s vhosts are re-rendered and the webserver is reloaded in the background.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Sets an HTML overlay (a banner, a script) that the host proxy writes into every HTML page of every project, before </body> or </head>. Projects can hide it.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['html'],
            properties: [
                new OA\Property(property: 'html', type: 'string', description: 'The HTML written into every HTML page.'),
                new OA\Property(property: 'position', type: 'string', enum: ['body_end', 'head_end'], nullable: true, description: 'Defaults to body_end.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The global overlay', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'overlay', ref: '#/components/schemas/Overlay', nullable: true)],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(OverlaySetRequest $request): JsonResponse
    {
        $this->overlays->setGlobal($request->overlay());

        return $this->answer();
    }

    #[OA\Delete(
        path: '/system/overlay',
        summary: 'Remove the global overlay',
        description: 'Stops writing the global overlay into pages. Projects\' own overlays stay. '
            .'The global overlay text goes and is not kept, so it has to be written again; this cannot be undone. '
            .'Ask the operator to confirm first.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'No global overlay', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'overlay', ref: '#/components/schemas/Overlay', nullable: true)],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(): JsonResponse
    {
        $this->overlays->removeGlobal();

        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['overlay' => $this->overlays->global()?->toArray()]);
    }
}
