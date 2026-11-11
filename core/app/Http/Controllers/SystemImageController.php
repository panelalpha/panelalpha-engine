<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemImageStoreRequest;
use App\Http\Requests\SystemImageUpdateRequest;
use App\Jobs\ApplyOperatorImage;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Lib\Deploy\CacheManager\SystemImageListing;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The cache registry's base images: the operator's and the engine's catalogue.
 * A token scoped to some projects is refused, as on every global route.
 */
class SystemImageController extends Controller
{
    #[OA\Get(
        path: '/system/images',
        summary: 'List the base images this host keeps for its projects',
        description: 'The operator\'s own images first, then the engine\'s catalogue, each with whether the cache '
            .'registry holds it right now (`present`) and the digest it serves. An application that builds FROM an '
            .'operator image can check `present` before a deploy and fall back to a full build when it is false. '
            .'`present` is null when the registry could not be read. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lists the base images this host keeps in its cache registry: the operator\'s own and the engine\'s catalogue, each with whether the registry holds it now and its digest. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The images', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SystemImage'))],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => SystemImageListing::make()->all()]);
    }

    #[OA\Get(
        path: '/system/images/{id}',
        summary: 'Get one operator image',
        description: 'The operator image with its Dockerfile, the state of its last operation, and whether the cache '
            .'registry holds it right now. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Gets one operator base image: its source, Dockerfile, last operation\'s state and error, and whether the cache registry holds it now. Refused 403 for a project-scoped token.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The image', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage')],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No operator image with this id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => SystemImageListing::make()->operator(OperatorImages::findOrFail($id))]);
    }

    #[OA\Post(
        path: '/system/images',
        summary: 'Add an operator base image',
        description: 'Records the image and queues a task that gets it into the cache registry, where every project on '
            .'this host can build FROM it by its plain name. `pull` pulls `ref` from its registry. `build` builds '
            .'`dockerfile` on this host with no build context, so it cannot COPY files in: write them with RUN. `local` '
            .'pushes an image already on the host. Everything in the registry is readable by every project here: put '
            .'no secrets, licence keys or licensed code in the Dockerfile or the build arguments. A `build` or `local` '
            .'ref may not name a registry host, a name the engine uses, or a name Docker Hub publishes (422), since '
            .'every project would get this image instead. A ref already added is refused with 409. Refused with 403 '
            .'for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Adds an operator base image to this host\'s cache registry (source pull, build from a Dockerfile with no context, or local), queued as a task. Every project on the host can then build FROM it; it is readable by all of them, so no secrets. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ref', 'source'],
            properties: [
                new OA\Property(property: 'ref', type: 'string', example: 'whmcs-dev-base:php8.3', description: 'The image name with a tag.'),
                new OA\Property(property: 'source', type: 'string', enum: ['pull', 'build', 'local'], example: 'build'),
                new OA\Property(property: 'dockerfile', type: 'string', nullable: true, description: 'The Dockerfile text; build only.'),
                new OA\Property(property: 'build_args', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Build arguments; build only. They show in the image history.'),
                new OA\Property(property: 'why', type: 'string', nullable: true, description: 'A note on what the image is for.'),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'The image, and the task that publishes it', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage'),
                    new OA\Property(property: 'task_id', type: 'integer', example: 42),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'An operator image with this ref already exists', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'An invalid or refused ref, source, Dockerfile or build arguments, a local image the host does not have, or a build or local name that could not be checked against Docker Hub because registry-proxy did not answer (`operator_image_ref_unverified`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(SystemImageStoreRequest $request): JsonResponse
    {
        $image = OperatorImages::make()->createQueued($request->validated());

        return new JsonResponse(['data' => SystemImageListing::make()->operator($image->refresh()), 'task_id' => $image->task_id], 202);
    }

    #[OA\Put(
        path: '/system/images/{id}',
        summary: 'Change an operator base image',
        description: 'Changes `why`, and for a build image `dockerfile` and `build_args`. A changed build queues a '
            .'rebuild and answers 202 with its task; changing only `why` answers 200. Refused with 409 while an '
            .'earlier operation on the image is still running, and with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Changes an operator base image\'s note, or a build image\'s Dockerfile and build arguments; a changed build is rebuilt and re-published as a task. Refused 403 for a project-scoped token.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'dockerfile', type: 'string', nullable: true, description: 'The new Dockerfile text; build only.'),
                new OA\Property(property: 'build_args', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'The new build arguments, replacing the old; build only.'),
                new OA\Property(property: 'why', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'The image; nothing to rebuild', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage')],
            )),
            new OA\Response(response: 202, description: 'The image, and the task that rebuilds it', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage'),
                    new OA\Property(property: 'task_id', type: 'integer', example: 43),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No operator image with this id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'An earlier operation on the image is still running', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'An invalid Dockerfile, build arguments or note, or a build change on an image that is not built', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(int $id, SystemImageUpdateRequest $request): JsonResponse
    {
        $image = OperatorImages::findOrFail($id);
        if (! OperatorImages::make()->updateQueued($image, $request->validated())) {
            return new JsonResponse(['data' => SystemImageListing::make()->operator($image)]);
        }

        return new JsonResponse(['data' => SystemImageListing::make()->operator($image->refresh()), 'task_id' => $image->task_id], 202);
    }

    #[OA\Post(
        path: '/system/images/{id}/refresh',
        summary: 'Refresh an operator base image now',
        description: 'Queues what the weekly refresh does: a pull image is pulled again, a build image is rebuilt from '
            .'upstream with no build cache, and a local image is pushed again from the host copy, or kept as the '
            .'registry has it when the host copy is gone. A failure leaves the previous copy in the registry. Refused '
            .'with 409 while an earlier operation is still running, and with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Re-pulls, rebuilds from upstream, or re-pushes an operator base image now, as a task; a failure keeps the previous copy. Refused 403 for a project-scoped token.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 202, description: 'The image, and the refresh task', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage'),
                    new OA\Property(property: 'task_id', type: 'integer', example: 44),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No operator image with this id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'An earlier operation on the image is still running', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function refresh(int $id): JsonResponse
    {
        $image = OperatorImages::findOrFail($id);
        $task = OperatorImages::make()->queue($image, ApplyOperatorImage::REFRESH);

        return new JsonResponse(['data' => SystemImageListing::make()->operator($image->refresh()), 'task_id' => $task->id], 202);
    }

    #[OA\Delete(
        path: '/system/images/{id}',
        summary: 'Remove an operator base image',
        description: 'Queues a task that takes the image out of the cache registry, garbage-collects, removes the host '
            .'copies the engine made (a local image\'s own tag stays), and then forgets it. Projects that already '
            .'have it keep their copy; a new build FROM it fails until it is added again. Refused with 409 while an '
            .'earlier operation is still running, and with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Removes an operator base image from this host\'s cache registry and forgets it, as a task. A new build FROM it fails afterwards. Refused 403 for a project-scoped token.'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 202, description: 'The image as it is being removed, and the task', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/SystemImage'),
                    new OA\Property(property: 'task_id', type: 'integer', example: 45),
                ],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'No operator image with this id', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'An earlier operation on the image is still running', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        $image = OperatorImages::findOrFail($id);
        $task = OperatorImages::make()->queue($image, ApplyOperatorImage::REMOVE);

        return new JsonResponse(['data' => SystemImageListing::make()->operator($image->refresh()), 'task_id' => $task->id], 202);
    }
}
