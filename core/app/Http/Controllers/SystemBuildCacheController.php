<?php

namespace App\Http\Controllers;

use App\Http\Requests\SystemBuildCacheRequest;
use App\Lib\Deploy\CacheManager\BuildCaches;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * BuildKit caches of public repositories, for any project deploying one to
 * import. A token scoped to some projects is refused, as on every global route.
 */
class SystemBuildCacheController extends Controller
{
    #[OA\Get(
        path: '/system/build-caches',
        summary: 'List the build caches this host keeps for its projects',
        description: 'One entry per repository, branch and build (its own Dockerfile, a compose build service, or '
            .'its Railpack plan). A deploy of that repository and branch imports the matching cache by itself. '
            .'Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Lists the BuildKit caches this host keeps for public repositories: one per repository, branch and build. A deploy of that repository and branch imports its cache by itself. Refused 403 for a project-scoped token.'],
        responses: [
            new OA\Response(response: 200, description: 'The caches', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SystemBuildCache'))],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 503, description: 'The cache registry could not be read', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): JsonResponse
    {
        try {
            return new JsonResponse(['data' => BuildCaches::make()->all()]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 503);
        }
    }

    #[OA\Post(
        path: '/system/build-caches',
        summary: 'Build a public repository\'s caches',
        description: 'Queues a task that clones the repository and builds it on this host the way a deploy would, '
            .'then publishes one BuildKit cache per build: its own Dockerfile, each compose service that builds from '
            .'the checkout, or its Railpack plan. The build runs isolated, with no access to the host, and projects '
            .'only ever read the caches. A cache helps a deploy of the same commit: build again when the repository '
            .'moves on. The task fails, saying why, for a repository that builds nothing a cache can hold or that '
            .'deploys through an app config. Only a public https URL is accepted (422); a build of the same '
            .'repository and branch already queued is refused with 409. Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Builds a public repository on this host the way a deploy would and publishes its BuildKit caches, queued as a task; later deploys of that repository and branch import them. Only a public https URL. Refused 403 for a project-scoped token.'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['repository'],
            properties: [
                new OA\Property(property: 'repository', type: 'string', example: 'https://github.com/TwiN/gatus', description: 'A public https clone URL.'),
                new OA\Property(property: 'branch', type: 'string', nullable: true, example: 'master', description: 'The branch projects deploy; the default branch when omitted.'),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'The task that builds and publishes the caches', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'task_id', type: 'integer', example: 42)],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'A build of this repository and branch is already queued or running (`build_cache_busy`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'Not a public https clone URL (`build_cache_repository_refused`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(SystemBuildCacheRequest $request): JsonResponse
    {
        $task = BuildCaches::make()->queueBuild($request->repository(), $request->branch());

        return new JsonResponse(['task_id' => $task->id], 202);
    }

    #[OA\Delete(
        path: '/system/build-caches',
        summary: 'Remove a repository\'s build caches',
        description: 'Removes the repository\'s caches from the cache registry, of one branch or of all of them; the '
            .'space comes back at the next registry garbage-collect. The weekly image refresh keeps build caches, so '
            .'this is what removes them. Removing what is not there answers 200 with nothing removed. Only a public '
            .'https URL (422). Refused with 403 for a token scoped to some projects.',
        security: [['bearerAuth' => []]],
        tags: ['System'],
        x: ['mcp-description' => 'Removes a repository\'s BuildKit caches from this host\'s cache registry, of one branch or all. Refused 403 for a project-scoped token. '
            .'The cached layers go and the next build compiles them again; caches are not kept. This cannot be '
            .'undone. Ask the operator to confirm first.'],
        parameters: [
            new OA\Parameter(name: 'repository', in: 'query', required: true, description: 'The clone URL the caches were built from.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch', in: 'query', required: false, description: 'Only this branch; every branch when omitted.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The refs removed', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'removed', type: 'array', items: new OA\Items(type: 'string'))],
            )),
            new OA\Response(response: 403, description: 'The caller\'s token is scoped to some projects', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Not a public https clone URL (`build_cache_repository_refused`)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 503, description: 'The cache registry could not be read or locked', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(SystemBuildCacheRequest $request): JsonResponse
    {
        try {
            return new JsonResponse(['removed' => BuildCaches::make()->remove($request->repository(), $request->branch())]);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 503);
        }
    }
}
