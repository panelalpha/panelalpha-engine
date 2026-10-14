<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\MysqlUsePmaSsoTokenRequest;
use App\Models\MysqlSsoToken;
use App\Models\User;
use App\System\Project\Dind\AppDatabase;
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
            'host' => $this->hostReachableFromAccount(),
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
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function createPhpmyadminSsoToken(string $username): JsonResponse
    {
        $user = $this->projectOr404($username);
        $this->refuseSuspendedProject($user);
        $domain = $user->getMainDomain() ?? abort(404, 'Not found');

        $token = $this->replaceSsoToken($user);
        $url = "https://{$domain->domain}/phpmyadmin/signon.php?pmassotoken={$token->token}";

        return new JsonResponse(['data' => ['url' => $url]]);
    }

    /** The project's only SSO token: any earlier one is revoked. */
    private function replaceSsoToken(User $user): MysqlSsoToken
    {
        $user->mysqlSsoTokens()->delete();

        return MysqlSsoToken::create([
            'user_id' => $user->id,
            'token' => Str::random(64),
            'expires_at' => Carbon::now()->addMinutes(5),
        ]);
    }

    #[OA\Put(
        path: '/mysql/phpmyadmin-sso-token',
        summary: 'Consume a phpMyAdmin SSO token (internal use, no bearer auth)',
        tags: ['MySQL Server'],
        // phpMyAdmin's signon.php redeems this; PmaSso refuses anyone else,
        // an in-process tool call included. The browser URL is the login.
        x: ['mcp-hide' => true],
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

        $token = $this->usableToken($params['token']);
        $credentials = $token->getCredentials();

        return new JsonResponse(['data' => $credentials]);
    }

    /** The name a DinD app's compose pins ({@see AppDatabase::extraHosts()}), so it resolves from inside the account. */
    private function hostReachableFromAccount(): string
    {
        return AppDatabase::hostname();
    }

    /**
     * A token is redeemed through any domain's /phpmyadmin, not only this project's, so a suspended
     * vhost alone does not cut database access.
     *
     * @throws ValidationException
     */
    private function refuseSuspendedProject(User $user): void
    {
        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'project' => 'The project is suspended; phpMyAdmin is not available for it.',
            ]);
        }
    }

    /**
     * The token, claimed (deleted) before anything is handed out so only the request whose delete removed
     * the row gets credentials. One minted before its project was suspended is refused all the same.
     *
     * @throws ValidationException
     */
    private function usableToken(string $value): MysqlSsoToken
    {
        $token = MysqlSsoToken::claim($value);

        if (!$token) {
            throw ValidationException::withMessages([
                'token' => 'Invalid token'
            ]);
        }

        if ($token->expired()) {
            throw ValidationException::withMessages([
                'token' => 'Invalid token expired'
            ]);
        }

        if ($token->user->status === 'suspended') {
            throw ValidationException::withMessages([
                'token' => 'Invalid token'
            ]);
        }

        return $token;
    }
}
