<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectPasswordSetRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\System\Project\SitePasswordProtection;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ProjectPasswordController extends Controller
{
    #[OA\Put(
        path: '/projects/{username}/password',
        summary: 'Set site password protection for a project',
        description: 'Protects all domains of the project (nginx-proxy). Username in Basic Auth is ignored; only the password matters. Host-wide UX is SITE_PASSWORD_AUTH_MODE=custom|basic.',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['password'],
                properties: [
                    new OA\Property(property: 'password', type: 'string', minLength: 1, example: 's3cret'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password set', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function update(string $username, ProjectPasswordSetRequest $request): UserResource
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse(['message' => 'User not found'], 404));
        }

        SitePasswordProtection::set($user, (string) $request->validated('password'));
        $user->refresh();

        return new UserResource($user);
    }

    #[OA\Delete(
        path: '/projects/{username}/password',
        summary: 'Remove site password protection from a project',
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Password removed', content: new OA\JsonContent(ref: '#/components/schemas/User')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username): UserResource
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse(['message' => 'User not found'], 404));
        }

        SitePasswordProtection::unset($user);
        $user->refresh();

        return new UserResource($user);
    }
}
