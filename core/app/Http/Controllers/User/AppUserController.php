<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Lib\Helpers\SafeRedirect;
use App\System\Project\Dind;
use App\System\Project\Dind\AppManager;
use App\Models\AppSsoToken;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AppUserController extends Controller
{
    /**
     * App management is backed by the app config's overrides/app.sh
     * (legacy panelalpha-app.sh), which only exists for DinD projects.
     */
    private function appManager(string $username): AppManager
    {
        $user = User::findByUsername($username);
        if (!$user) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }
        if ($user->getTemplate() !== 'dind') {
            abort(new JsonResponse(['message' => 'App user management is only available for dind users'], 403));
        }

        $runtime = $user->project()->runtime();
        if (!$runtime instanceof Dind) {
            abort(new JsonResponse(['message' => 'App user management is only available for dind users'], 403));
        }

        return $runtime->apps();
    }

    /**
     * appManager() aborts with its own status and message (e.g. "only
     * available for dind users") via Laravel's abort(Response) helper, which
     * throws HttpResponseException -- a plain \Exception whose own
     * getMessage() is empty, since the real message lives in the wrapped
     * response, not the exception. Catching it as a generic \Exception and
     * re-wrapping it in withMessages([$e->getMessage()]) silently replaced
     * that deliberate response with an empty-message 422. Let it through
     * unchanged; only a genuine app-layer failure becomes a validation error.
     *
     * @throws HttpResponseException
     * @throws ValidationException
     */
    private function rethrowAppException(\Exception $e): never
    {
        if ($e instanceof HttpResponseException) {
            throw $e;
        }

        throw ValidationException::withMessages([
            'app' => $e->getMessage(),
        ]);
    }

    #[OA\Get(
        path: '/projects/{username}/app/users',
        summary: 'List app users (e.g. WordPress users)',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of app users', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AppUser'))],
            )),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): JsonResponse
    {
        try {
            $users = $this->appManager($username)->listUsers();
            return new JsonResponse(['data' => $users]);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Post(
        path: '/projects/{username}/app/users',
        summary: 'Create an app user',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['login', 'email', 'password', 'role'],
            properties: [
                new OA\Property(property: 'login', type: 'string', example: 'jdoe'),
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'jdoe@example.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'SecurePass123'),
                new OA\Property(property: 'role', type: 'string', example: 'administrator'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'App user created', content: new OA\JsonContent(ref: '#/components/schemas/AppUser')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(string $username, Request $request): JsonResponse
    {
        // TODO email address may be not supported (e.g. umami app)
        /** @var array{login: string, email: string, password: string, role: string} $validated */
        $validated = $request->validate([
            'login'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'string'],
        ]);

        try {
            $result = $this->appManager($username)->addUser(
                $validated['login'],
                $validated['email'],
                $validated['password'],
                $validated['role'],
            );
            return new JsonResponse(['data' => $result], 201);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Delete(
        path: '/projects/{username}/app/users/{userId}',
        summary: 'Delete an app user',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'App user deleted'),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(string $username, string $userId): JsonResponse
    {
        try {
            $this->appManager($username)->deleteUser($userId);
            return new JsonResponse(null, 204);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Put(
        path: '/projects/{username}/app/users/{userId}/password',
        summary: 'Reset an app user password',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password'],
            properties: [new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8)],
        )),
        responses: [
            new OA\Response(response: 204, description: 'Password reset'),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function resetPassword(string $username, string $userId, Request $request): JsonResponse
    {
        /** @var array{password: string} $validated */
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        try {
            $this->appManager($username)->resetUserPassword($userId, $validated['password']);
            return new JsonResponse(null, 204);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Post(
        path: '/projects/{username}/app/users/{userId}/sso',
        summary: 'Create an SSO token for an app user',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SSO URL', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'url', type: 'string', format: 'uri')],
            )),
            new OA\Response(response: 422, description: 'Error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function createSsoToken(string $username, string $userId): JsonResponse
    {
        try {
            $result = $this->appManager($username)->ssoCredentials($userId);

            // Pattern A – app handles SSO itself and returns a ready-to-use URL
            // (e.g. WordPress: ?panelalpha_sso=<token> handled by the mu-plugin).
            if (!empty($result['url'])) {
                return new JsonResponse(['url' => $result['url']]);
            }

            // Pattern C – app returns a path (including query string) and the engine
            // prepends the user's primary domain to form the full URL.
            if (!empty($result['path'])) {
                $userModel = User::findByUsername($username);
                $domain    = $userModel?->getMainDomain();
                if (!$domain) {
                    throw new \Exception('No primary domain configured for this user');
                }
                $scheme = $domain->sslEnabled() ? 'https' : 'http';
                $url    = "{$scheme}://{$domain->domain}{$result['path']}";
                return new JsonResponse(['url' => $url]);
            }

            // Pattern B – app returns cookie credentials; the engine sets the
            // cookie via /panelalpha-sso and redirects the browser into the app.
            if (empty($result['cookie']) || empty($result['value'])) {
                throw new \Exception('Invalid SSO response returned by app');
            }

            $userModel = User::findByUsername($username);
            $domain    = $userModel?->getMainDomain();

            if (!$domain) {
                throw new \Exception('No primary domain configured for this user');
            }

            $scheme   = $domain->sslEnabled() ? 'https' : 'http';
            $redirect = $result['redirect'] ?? '/';

            $token = AppSsoToken::create([
                'token'        => Str::random(48),
                'username'     => $username,
                'cookie_name'  => $result['cookie'],
                'cookie_value' => $result['value'],
                'redirect'     => $redirect,
                'expires_at'   => now()->addSeconds(60),
            ]);

            $url = "{$scheme}://{$domain->domain}/panelalpha-sso?token={$token->token}";

            return new JsonResponse(['url' => $url]);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Get(
        path: '/projects/{username}/app/sso-token',
        summary: 'Consume an SSO token and redirect into the app (no bearer auth)',
        tags: ['App Users'],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to app with SSO cookie set'),
            new OA\Response(response: 404, description: 'Token not found or expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function useAppSsoToken(string $username): \Symfony\Component\HttpFoundation\Response
    {
        $tokenStr = (string) request()->query('token', '');

        /** @var ?AppSsoToken */
        $record = AppSsoToken::where('token', $tokenStr)
            ->where('username', $username)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        // Mark used before issuing the redirect to prevent replay.
        $record->update(['used_at' => now()]);

        $cookie = cookie(
            $record->cookie_name,
            $record->cookie_value,
            60 * 24,
            '/',
            null,
            request()->secure(),
            true,
            false,
            'Lax',
        );

        return redirect(SafeRedirect::toPath($record->redirect))->withCookie($cookie);
    }

    #[OA\Get(
        path: '/projects/{username}/app/info',
        summary: 'Get app info and capabilities',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'App info', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'object')],
            )),
        ],
    )]
    public function info(string $username): JsonResponse
    {
        try {
            $caps = $this->appManager($username)->info();
            return new JsonResponse(['data' => $caps]);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Get(
        path: '/projects/{username}/app/roles',
        summary: 'List available app roles',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of roles', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string'))],
            )),
        ],
    )]
    public function roles(string $username): JsonResponse
    {
        try {
            $roles = $this->appManager($username)->listRoles();
            return new JsonResponse(['data' => $roles]);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    #[OA\Post(
        path: '/projects/{username}/app/install',
        summary: 'Install the app (e.g. run WordPress installer)',
        security: [['bearerAuth' => []]],
        tags: ['App Users'],
        parameters: [new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url', 'title', 'admin_user', 'admin_email', 'admin_password'],
            properties: [
                new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://example.com'),
                new OA\Property(property: 'title', type: 'string', example: 'My Site'),
                new OA\Property(property: 'admin_user', type: 'string', example: 'admin'),
                new OA\Property(property: 'admin_email', type: 'string', format: 'email', example: 'admin@example.com'),
                new OA\Property(property: 'admin_password', type: 'string', format: 'password', minLength: 8),
            ],
        )),
        responses: [
            new OA\Response(response: 204, description: 'App installed'),
            new OA\Response(response: 422, description: 'Error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function install(string $username, Request $request): JsonResponse
    {
        /** @var array{url: string, title: string, admin_user: string, admin_email: string, admin_password: string} $validated */
        $validated = $request->validate([
            'url'            => ['required', 'url'],
            'title'          => ['required', 'string'],
            'admin_user'     => ['required', 'string'],
            'admin_email'    => ['required', 'email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        try {
            $this->appManager($username)->install(
                $validated['url'],
                $validated['title'],
                $validated['admin_user'],
                $validated['admin_email'],
                $validated['admin_password'],
            );
            return new JsonResponse(null, 204);
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }
}
