<?php

namespace App\Http\Controllers;

use App\Http\Requests\System\PrewarmUpdateRequest;
use App\Lib\Deploy\CacheManager\PrewarmSettings;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Which base images the host keeps ready before any deploy: what `pae configure prewarm` changes. A token scoped to
 * some projects is refused, as on every global route.
 */
class SystemPrewarmController extends Controller
{
    public function __construct(private readonly PrewarmSettings $settings) {}

    #[OA\Get(
        path: '/system/prewarm',
        summary: 'Read which base images the host keeps ready',
        description: 'The catalogue images the weekly prewarm builds or pulls ahead of any deploy (none by default), '
            .'the most disk one prewarm may spend (`budget`) and the free disk it never takes the host below '
            .'(`reserve`). `available` is every image config/core/images.yaml can prewarm; `images.unknown` names a '
            .'selected id the catalogue no longer has. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Reads which catalogue base images the host prewarms ahead of any deploy, the disk budget and reserve, and every image that could be selected. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The prewarm settings', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PrewarmSettings')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(): JsonResponse
    {
        return $this->answer();
    }

    #[OA\Put(
        path: '/system/prewarm',
        summary: 'Change which base images the host keeps ready',
        description: 'Stores the selection, the budget and the reserve in the engine database, where they win over '
            .'DEPLOY_PREWARM_IMAGES, DEPLOY_PREWARM_BUDGET and DEPLOY_PREWARM_RESERVE in .env-core; `pae configure '
            .'prewarm` saves the same. A field left out stays; null puts .env-core, or else the images.yaml default '
            .'(no images), back in charge. `images` is the whole selection, ids from `available` in GET, [] for none. '
            .'Nothing is pulled or deleted now: the next '
            .'prewarm (weekly, Sunday 03:30, or `pae system:image:prewarm`) follows it, and an image no longer selected '
            .'leaves the host once no deploy has used it for DEPLOY_HOST_IMAGE_RETENTION. Refused with 403 for a '
            .'token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Sets which catalogue base images the host prewarms (images: the whole selection, ids from system_prewarm_get), the disk one prewarm may spend (budget, e.g. 6G or none) and the free disk it keeps (reserve, e.g. 10G); null puts .env-core back in charge. Applies at the next prewarm. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'images', type: 'array', nullable: true, items: new OA\Items(type: 'string'), example: ['php:8.3', 'composer'], description: 'Every image to keep ready; [] for none, null for what .env-core says.'),
                new OA\Property(property: 'budget', type: 'string', nullable: true, example: '6G', description: 'A size like 6G or 512M, or none for anything down to the reserve; null for .env-core or the default.'),
                new OA\Property(property: 'reserve', type: 'string', nullable: true, example: '10G', description: 'A size like 10G; null for .env-core or the default.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The prewarm settings now', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PrewarmSettings')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(PrewarmUpdateRequest $request): JsonResponse
    {
        $this->settings->set($request->changes());

        return $this->answer();
    }

    private function answer(): JsonResponse
    {
        return new JsonResponse(['data' => $this->settings->toArray()]);
    }
}
