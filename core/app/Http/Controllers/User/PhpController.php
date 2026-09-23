<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPhpListCustomIniSettingsRequest;
use App\Http\Requests\UserPhpUpdateCustomIniSettingsRequest;
use App\Models\User;
use App\System as EngineSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PhpController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/php/custom-ini-settings',
        summary: 'Get custom PHP INI settings for a user',
        security: [['bearerAuth' => []]],
        tags: ['PHP'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'php_version', in: 'query', required: true, schema: new OA\Schema(type: 'string', example: '8.2')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Custom INI settings', content: new OA\JsonContent(ref: '#/components/schemas/PhpIniSettings')),
        ],
    )]
    public function listCustomIniSettings(string $username, UserPhpListCustomIniSettingsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = User::findByUsername($username);
        if(!$user) {
            abort(404, 'Not found');
        }

        /** @var array{php_version: string} */
        $params = $request->validated();
        $versions = $system->php()->listAvailablePhpVersions();
        if (!in_array($params['php_version'], $versions)) {
            throw ValidationException::withMessages([
                'php_version' => 'Invalid value',
            ]);
        }

        $data = $user->project()->php()->getCustomIniSettings($params['php_version']);

        return new JsonResponse([
            'data' => $data,
        ]);
    }

    #[OA\Put(
        path: '/projects/{username}/php/custom-ini-settings',
        summary: 'Update custom PHP INI settings for a user',
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
        ],
    )]
    public function updateCustomIniSettings(string $username, UserPhpUpdateCustomIniSettingsRequest $request, EngineSystem $system): JsonResponse
    {
        $user = User::findByUsername($username);
        if(!$user) {
            abort(404, 'Not found');
        }

        /** @var array{php_version: string, settings: array<string,string>} */
        $params = $request->validated();
        $versions = $system->php()->listAvailablePhpVersions();
        if (!in_array($params['php_version'], $versions)) {
            throw ValidationException::withMessages([
                'php_version' => 'Invalid value',
            ]);
        }

        try {
            // The version in the body is the identity of the set. Other
            // installed versions keep whatever they already have.
            $user->project($system)->php()->updateCustomIniSettings($params['php_version'], $params['settings']);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'settings' => 'Could not set php.ini directives. ' . $e->getMessage(),
            ]);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
