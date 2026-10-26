<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhpErrorLogRequest;
use App\Http\Requests\UserPhpListCustomIniSettingsRequest;
use App\Http\Requests\UserPhpUpdateCustomIniSettingsRequest;
use App\Lib\Project\CustomIniSettings;
use App\Lib\Project\LogTime;
use App\Lib\Project\PhpErrorLog;
use App\System as EngineSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class PhpController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/php/custom-ini-settings',
        summary: 'Get custom PHP INI settings of a project',
        description: 'PHP hosting projects only: a dind app reads php.ini from its own image, and a dind project answers 422.',
        security: [['bearerAuth' => []]],
        tags: ['PHP'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'php_version', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '8.2')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Custom INI settings', content: new OA\JsonContent(ref: '#/components/schemas/PhpIniSettings')),
            new OA\Response(response: 422, description: 'Not a PHP hosting project, or an unknown PHP version', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function listCustomIniSettings(string $username, UserPhpListCustomIniSettingsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /** @var array{php_version: string} */
        $params = $request->validated();
        $data = (new CustomIniSettings($system))->get($user, $params['php_version']);

        return new JsonResponse([
            'data' => $data,
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/php/custom-ini-settings',
        summary: 'Update custom PHP INI settings of a project',
        description: 'PHP hosting projects only: a dind app reads php.ini from its own image, and a dind project answers 422.',
        security: [['bearerAuth' => []]],
        tags: ['PHP'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['php_version', 'settings'],
            properties: [
                new OA\Property(property: 'php_version', type: 'string', example: '8.2'),
                new OA\Property(property: 'settings', type: 'object', example: ['memory_limit' => '256M']),
            ],
        )),
        responses: [
            new OA\Response(response: 204, description: 'INI settings updated'),
            new OA\Response(response: 422, description: 'Not a PHP hosting project, or invalid settings', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateCustomIniSettings(string $username, UserPhpUpdateCustomIniSettingsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /** @var array{php_version: string, settings: array<string,string>} */
        $params = $request->validated();
        (new CustomIniSettings($system))->replace($user, $params['php_version'], $params['settings']);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Get(
        path: '/projects/{username}/php/error-log',
        summary: 'Get the PHP error log of a project',
        description: 'PHP hosting projects read their own php_error.log, written by the PHP handlers into the project log '
            .'directory. A dind project returns its application service container output instead, with file null. '
            .'"since" drops classic lines whose timestamp is older. On classic hosting the log is project-wide, so '
            .'"domain" only checks that the domain belongs to the project.',
        security: [['bearerAuth' => []]],
        tags: ['PHP'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lines', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, minimum: 1, maximum: 5000)),
            new OA\Parameter(name: 'since', description: 'Only lines written after this: '.LogTime::EXPECTED.'.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'domain', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The newest lines of the PHP error log, oldest first', content: new OA\JsonContent(ref: '#/components/schemas/PhpErrorLog')),
            new OA\Response(response: 404, description: 'Domain not found for this project', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function errorLog(string $username, PhpErrorLogRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOr404($username);

        /** @var array{lines?: int, since?: string, domain?: string} $params */
        $params = $request->validated();
        $data = PhpErrorLog::for($user, $system)->read(
            (int) ($params['lines'] ?? 200),
            $params['since'] ?? null,
            $params['domain'] ?? null,
        );

        return new JsonResponse(['data' => $data]);
    }
}
