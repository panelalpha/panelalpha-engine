<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlUsePmaSsoTokenRequest;
use App\Models\MysqlSsoToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class MysqlController extends Controller
{
    #[OA\Get(
        path: '/projects/{username}/mysql/server-info',
        summary: 'Get MySQL server connection info',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Server'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Server info', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'host', type: 'string'),
                        new OA\Property(property: 'port', type: 'string'),
                    ]),
                ],
            )),
        ],
    )]
    /**
     * @return JsonResponse
     */
    public function serverInfo()
    {
        $data = [
            'host' => config('env.USERS_DB_HOST'),
            'port' => '3306',
        ];

        return new JsonResponse(['data' => $data]);
    }

    #[OA\Post(
        path: '/projects/{username}/mysql/phpmyadmin-sso-token',
        summary: 'Create a phpMyAdmin SSO token for a project',
        security: [['bearerAuth' => []]],
        tags: ['MySQL Server'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'SSO token URL', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'url', type: 'string', example: 'https://example.com/phpmyadmin/signon.php?pmassotoken=...'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function createPhpmyadminSsoToken(string $username): JsonResponse
    {
        $user = $this->projectOrNotFound($username);

        $user->mysqlSsoTokens()->delete();

        $token = MysqlSsoToken::create([
            'user_id' => $user->id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);

        $domain = $user->getMainDomain();
        if (!$domain) {
            abort(404, 'Not found');
        }
        $url = "https://{$domain->domain}/phpmyadmin/signon.php?pmassotoken={$token->token}";

        $data = [
            'url' => $url,
        ];

        return new JsonResponse(['data' => $data]);
    }

    #[OA\Put(
        path: '/mysql/phpmyadmin-sso-token',
        summary: 'Consume a phpMyAdmin SSO token (internal use, no bearer auth)',
        tags: ['MySQL Server'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'MySQL credentials for phpMyAdmin login', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'username', type: 'string'),
                        new OA\Property(property: 'password', type: 'string'),
                        new OA\Property(property: 'host', type: 'string'),
                    ]),
                ],
            )),
            new OA\Response(response: 422, description: 'Invalid or expired token', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function usePhpmyadminSsoToken(MysqlUsePmaSsoTokenRequest $request): JsonResponse
    {
        /** @var array{token: string} */
        $params = $request->validated();

        // Deleted before anything is handed out: only the request whose delete
        // removed the row gets credentials.
        $token = MysqlSsoToken::claim($params['token']);

        if (!$token) {
            throw ValidationException::withMessages([
                'Invalid token'
            ]);
        }

        if ($token->expired()) {
            throw ValidationException::withMessages([
                'Invalid token expired'
            ]);
        }

        $credentials = $token->getCredentials();

        return new JsonResponse(['data' => $credentials]);
    }
}
