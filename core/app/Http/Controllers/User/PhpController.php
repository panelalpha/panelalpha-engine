<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPhpListCustomIniSettingsRequest;
use App\Http\Requests\UserPhpUpdateCustomIniSettingsRequest;
use App\Lib\Project\CustomIniSettings;
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
        $user = $this->projectOrNotFound($username);

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
            new OA\Response(response: 200, description: 'INI settings updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Not a PHP hosting project, or invalid settings', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function updateCustomIniSettings(string $username, UserPhpUpdateCustomIniSettingsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = $this->projectOrNotFound($username);

        /** @var array{php_version: string, settings: array<string,string>} */
        $params = $request->validated();
        (new CustomIniSettings($system))->replace($user, $params['php_version'], $params['settings']);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
