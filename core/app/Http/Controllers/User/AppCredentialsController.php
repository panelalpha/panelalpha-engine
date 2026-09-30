<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Lib\Deploy\Credentials\AppCredentials;
use App\System\Project\Dind\Networking;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The login the engine generated for the deployed application, from the
 * recipe's `credentials:` declaration. Repeatable, not show-once: the engine
 * keeps the values anyway, to deliver them again on every deploy.
 */
class AppCredentialsController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/app-credentials',
        summary: "Get the application's generated login",
        description: 'The login the engine generated for the deployed application when its recipe declares '
            . '`credentials:` (an admin username and password the app is seeded with), with the URL to use it on. '
            . 'The same values are delivered to the account on every deploy as ~/.panelalpha/app-credentials.env. '
            . 'They are what the application was first seeded with: a password changed later inside the application '
            . 'is not reflected here. `available` is false, with no fields, when the application declares none. '
            . 'GET /projects/{username} says whether they exist without returning a value.',
        x: ['mcp-description' => 'The admin login the engine generated and seeded into the deployed app (from the '
            . 'recipe\'s `credentials:`), with `login_url`. `available: false` when the app declares none. Values '
            . 'are what the app was seeded with; a password changed inside the app later is not reflected.'],
        security: [['bearerAuth' => []]],
        tags: ['Projects'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'The generated login', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'available', type: 'boolean'),
                    new OA\Property(property: 'login_url', type: 'string', nullable: true, description: 'The public URL plus the recipe\'s `login_path`; null without a domain.'),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'fields', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'name', type: 'string', description: 'The environment variable the app reads it from, e.g. `SONARR_ADMIN_PASSWORD`.'),
                        new OA\Property(property: 'kind', type: 'string', enum: ['username', 'email', 'password']),
                        new OA\Property(property: 'value', type: 'string'),
                    ], type: 'object')),
                ], type: 'object')],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $username): JsonResponse
    {
        $user = $this->projectOr404($username);
        $stored = $user->getAppCredentials();

        return new JsonResponse([
            'data' => AppCredentials::reveal($stored, $stored === null ? null : Networking::publicUrlOf($user)),
        ]);
    }
}
