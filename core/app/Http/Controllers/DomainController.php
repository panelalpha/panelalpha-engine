<?php

namespace App\Http\Controllers;

use App\Http\Requests\DomainReplacePhpDirectivesRequest;
use App\Http\Requests\DomainSetPhpVersionRequest;
use App\Http\Resources\DomainResource;
use App\System;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class DomainController extends Controller
{
    #[OA\Get(
        path: '/domains/{domain}',
        summary: 'Get a domain by name (system-wide)',
        security: [['bearerAuth' => []]],
        tags: ['Domains'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Domain details', content: new OA\JsonContent(ref: '#/components/schemas/Domain')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $domain): DomainResource|JsonResponse
    {
        $domainModel = Domain::findByName($domain);
        if (!$domainModel) {
            return new JsonResponse('Not Found', 404);
        }

        return new DomainResource($domainModel);
    }

    #[OA\Get(
        path: '/domains/{domain}/php-version',
        summary: 'Get PHP version for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domain PHP'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'PHP version', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'string', example: '8.2', nullable: true)],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function getPhpVersion(string $domain): JsonResponse
    {
        $domainModel = Domain::findByName($domain);
        if (!$domainModel) {
            return new JsonResponse('Not Found', 404);
        }

        $version = $domainModel->getPhpVersion();

        return new JsonResponse(['data' => $version]);
    }

    #[OA\Put(
        path: '/domains/{domain}/php-version',
        summary: 'Set PHP version for a domain',
        security: [['bearerAuth' => []]],
        tags: ['Domain PHP'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['version'],
            properties: [new OA\Property(property: 'version', type: 'string', example: '8.2')],
        )),
        responses: [
            new OA\Response(response: 204, description: 'PHP version set'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function setPhpVersion(string $domain, DomainSetPhpVersionRequest $request): JsonResponse
    {
        $domainModel = Domain::findByName($domain);
        if (!$domainModel) {
            return new JsonResponse('Not Found', 404);
        }

        /** @var array{version: string} */
        $params = $request->validated();

        $system = new System();
        $versions = $system->php()->listAvailablePhpVersions();

        if (!in_array($params['version'], $versions)) {
            throw ValidationException::withMessages([
                'version' => 'Invalid value',
            ]);
        }

        $domainModel->setPhpVersion($params['version']);
        $domainModel->save();
        $domainModel->projectDomain()->rebuild();

        $domainModel->getUser()->project()->syncPhpHandlersScripts();
        $domainModel->getUser()->project()->runEntrypointScriptsSync();
        // A 204 has no body to flag a pending reload in; reloadWebserver() logs it.
        $system->reloadWebserver();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Get(
        path: '/domains/{domain}/php-directives',
        summary: 'Get domain PHP directives',
        security: [['bearerAuth' => []]],
        tags: ['Domain PHP'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Domain PHP directives', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object', example: ['memory_limit' => '256M'])],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Directive file is not valid INI', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function getPhpDirectives(string $domain, System $system): JsonResponse
    {
        $domainModel = Domain::findByName($domain);
        if (!$domainModel) {
            return new JsonResponse('Not Found', 404);
        }

        $data = $domainModel->user->project($system)->php()->getDomainDirectives($domainModel);

        return new JsonResponse([
            'data' => $data === [] ? new \stdClass() : $data,
        ]);
    }

    #[OA\Put(
        path: '/domains/{domain}/php-directives',
        summary: 'Replace domain PHP directives',
        security: [['bearerAuth' => []]],
        tags: ['Domain PHP'],
        parameters: [new OA\Parameter(name: 'domain', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['settings'],
            properties: [
                new OA\Property(property: 'settings', type: 'object', example: ['memory_limit' => '256M']),
            ],
        )),
        responses: [
            new OA\Response(response: 204, description: 'Domain PHP directives replaced'),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function setPhpDirectives(string $domain, DomainReplacePhpDirectivesRequest $request, System $system): JsonResponse
    {
        $domainModel = Domain::findByName($domain);
        if (!$domainModel) {
            return new JsonResponse('Not Found', 404);
        }

        /** @var array{settings: array<string, string>} */
        $params = $request->validated();
        $domainModel->user->project($system)->php()->replaceDomainDirectives($domainModel, $params['settings']);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
