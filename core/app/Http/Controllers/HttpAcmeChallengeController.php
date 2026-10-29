<?php

namespace App\Http\Controllers;

use App\Http\Requests\HttpAcmeChallengeStoreRequest;
use App\Lib\HttpAcmeChallengeStore;
use App\Models\Domain;
use App\System;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use OpenApi\Attributes as OA;

class HttpAcmeChallengeController extends Controller
{
    #[OA\Get(
        path: '/domains/{domain}/http-acme-challenges',
        summary: 'List HTTP-01 ACME challenges for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domain ACME'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Challenge list'),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function index(string $domain): JsonResponse
    {
        $domainModel = $this->resolveDomain($domain);
        if (! $domainModel) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        $store = new HttpAcmeChallengeStore;
        $primary = $domainModel->domain;

        return new JsonResponse([
            'data' => [
                'domain' => $primary,
                'challenges' => $store->list($primary),
            ],
        ]);
    }

    #[OA\Get(
        path: '/domains/{domain}/http-acme-challenges/{token}',
        summary: 'Get a single HTTP-01 ACME challenge',
        security: [['bearerAuth' => []]],
        tags: ['Domain ACME'],
        parameters: [
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Challenge'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(string $domain, string $token): JsonResponse
    {
        $domainModel = $this->resolveDomain($domain);
        if (! $domainModel) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        $store = new HttpAcmeChallengeStore;
        try {
            $content = $store->get($domainModel->domain, $token);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if ($content === null) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        return new JsonResponse([
            'data' => [
                'domain' => $domainModel->domain,
                'token' => $token,
                'content' => $content,
            ],
        ]);
    }

    #[OA\Post(
        path: '/domains/{domain}/http-acme-challenges',
        summary: 'Create an HTTP-01 ACME challenge',
        security: [['bearerAuth' => []]],
        tags: ['Domain ACME'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token', 'content'],
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'content', type: 'string'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'Challenge created'),
            new OA\Response(response: 404, description: 'Domain not found'),
            new OA\Response(response: 409, description: 'Token already exists'),
        ],
    )]
    public function store(string $domain, HttpAcmeChallengeStoreRequest $request): JsonResponse
    {
        $domainModel = $this->resolveDomain($domain);
        if (! $domainModel) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        /** @var array{token: string, content: string} $params */
        $params = $request->validated();
        $store = new HttpAcmeChallengeStore;
        $primary = $domainModel->domain;

        try {
            $result = $store->put($primary, $params['token'], $params['content']);
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === 'Challenge token already exists') {
                return new JsonResponse(['message' => $e->getMessage()], 409);
            }

            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if ($result['became_enabled']) {
            $this->rebuildAndReload($domainModel);
        }

        return new JsonResponse([
            'data' => [
                'domain' => $primary,
                'token' => $params['token'],
                'content' => $params['content'],
            ],
        ], 201);
    }

    #[OA\Delete(
        path: '/domains/{domain}/http-acme-challenges',
        summary: 'Delete all HTTP-01 ACME challenges for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domain ACME'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Domain not found'),
        ],
    )]
    public function destroyAll(string $domain): Response|JsonResponse
    {
        $domainModel = $this->resolveDomain($domain);
        if (! $domainModel) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        $store = new HttpAcmeChallengeStore;
        $result = $store->deleteAll($domainModel->domain);
        if ($result['became_disabled']) {
            $this->rebuildAndReload($domainModel);
        }

        return response()->noContent();
    }

    #[OA\Delete(
        path: '/domains/{domain}/http-acme-challenges/{token}',
        summary: 'Delete a single HTTP-01 ACME challenge',
        security: [['bearerAuth' => []]],
        tags: ['Domain ACME'],
        parameters: [
            new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(string $domain, string $token): Response|JsonResponse
    {
        $domainModel = $this->resolveDomain($domain);
        if (! $domainModel) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        $store = new HttpAcmeChallengeStore;
        try {
            $result = $store->delete($domainModel->domain, $token);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        if (! $result['deleted']) {
            return new JsonResponse(['message' => 'Not Found'], 404);
        }

        if ($result['became_disabled']) {
            $this->rebuildAndReload($domainModel);
        }

        return response()->noContent();
    }

    private function resolveDomain(string $domain): ?Domain
    {
        return Domain::findByNameOrAlias(strtolower($domain));
    }

    private function rebuildAndReload(Domain $domain): void
    {
        $domain->projectDomain()->rebuild();
        (new System)->webserver()->reload();
    }
}
