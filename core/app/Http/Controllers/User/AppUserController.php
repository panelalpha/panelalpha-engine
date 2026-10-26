<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Lib\Helpers\SafeRedirect;
use App\Lib\Vault\RequestVault;
use App\Models\AppSsoToken;
use App\Models\User;
use App\System\Project\Dind;
use App\System\Project\Dind\AppManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class AppUserController extends Controller
{
    /**
     * App management is backed by the app config's overrides/app.sh
     * (legacy panelalpha-app.sh), which only exists for DinD projects. A
     * classic project is refused in the name of the operation it asked for.
     */
    private function appManager(string $username, string $operation = 'App user management'): AppManager
    {
        $user = $this->projectOr404($username);
        if ($user->getTemplate() !== 'dind') {
            $this->refuseNonContainerProject($operation);
        }

        $runtime = $user->project()->runtime();
        if (! $runtime instanceof Dind) {
            $this->refuseNonContainerProject($operation);
        }

        return $runtime->apps();
    }

    private function refuseNonContainerProject(string $operation): never
    {
        abort(new JsonResponse(['message' => "{$operation} works only on container projects (template dind)."], 403));
    }

    /**
     * Runs an app call, turning a genuine app-layer failure into a 422 on `app`.
     *
     * @param  callable(): JsonResponse  $call
     */
    private function callApp(callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (\Exception $e) {
            $this->rethrowAppException($e);
        }
    }

    /**
     * abort()'s HttpResponseException passes through with its own status and message, since
     * its getMessage() is empty; any other failure becomes a validation error.
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
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(string $username): JsonResponse
    {
        return $this->callApp(fn () => new JsonResponse(['data' => $this->appManager($username)->listUsers()]));
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
            new OA\Response(response: 201, description: 'App user created; the app assigns the id, returned as a string', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AppUser')],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(string $username, Request $request): JsonResponse
    {
        self::resolveVaultField($request, 'password', $username);
        /** @var array{login: string, email: string, password: string, role: string} $validated */
        $validated = $request->validate(self::newUserRules());

        return $this->callApp(fn () => new JsonResponse(['data' => $this->appManager($username)->addUser(
            $validated['login'],
            $validated['email'],
            $validated['password'],
            $validated['role'],
        )], 201));
    }

    /**
     * TODO: an email address may not be supported by every app (e.g. umami).
     *
     * @return array<string, list<string>>
     */
    private static function newUserRules(): array
    {
        return [
            'login' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string'],
        ];
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
        return $this->callApp(function () use ($username, $userId) {
            $this->appManager($username)->deleteUser($userId);

            return new JsonResponse(null, 204);
        });
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
        self::resolveVaultField($request, 'password', $username);
        /** @var array{password: string} $validated */
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        return $this->callApp(function () use ($username, $userId, $validated) {
            $this->appManager($username)->resetUserPassword($userId, $validated['password']);

            return new JsonResponse(null, 204);
        });
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
        return $this->callApp(fn () => $this->ssoResponse($username, $this->appManager($username, 'App single sign-on')->ssoCredentials($userId)));
    }

    /**
     * The sign-in URL for one of the three answers an app's SSO gives: a ready URL (the app handles
     * SSO itself, e.g. WordPress's mu-plugin), a path on the project's primary domain, or cookie
     * credentials the engine sets through /panelalpha-sso before redirecting into the app.
     *
     * @param  array<string, mixed>  $result
     */
    private function ssoResponse(string $username, array $result): JsonResponse
    {
        if (! empty($result['url'])) {
            return new JsonResponse(['url' => $result['url']]);
        }

        if (! empty($result['path'])) {
            return new JsonResponse(['url' => $this->primaryDomainOrigin($username).$result['path']]);
        }

        if (empty($result['cookie']) || empty($result['value'])) {
            throw new \Exception('Invalid SSO response returned by app');
        }

        return new JsonResponse(['url' => $this->cookieHandoffUrl($username, $result)]);
    }

    /** @param array<string, mixed> $result */
    private function cookieHandoffUrl(string $username, array $result): string
    {
        $origin = $this->primaryDomainOrigin($username);

        $token = AppSsoToken::create([
            'token' => Str::random(48),
            'username' => $username,
            'cookie_name' => $result['cookie'],
            'cookie_value' => $result['value'],
            'redirect' => $result['redirect'] ?? '/',
            'expires_at' => now()->addSeconds(60),
        ]);

        return "{$origin}/panelalpha-sso?token={$token->token}";
    }

    /** @throws \Exception when the project has no primary domain */
    private function primaryDomainOrigin(string $username): string
    {
        $domain = User::findByUsername($username)?->getMainDomain();
        if (! $domain) {
            throw new \Exception('No primary domain configured for this user');
        }
        $scheme = $domain->sslEnabled() ? 'https' : 'http';

        return "{$scheme}://{$domain->domain}";
    }

    #[OA\Get(
        path: '/projects/{username}/app/sso-token',
        summary: 'Consume an SSO token and redirect into the app (no bearer auth)',
        tags: ['App Users'],
        // A browser redeems this; as a tool it burns the single-use token and
        // hands the session cookie to the agent. app_user_sso_create is the login.
        x: ['mcp-hide' => true],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'token', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to app with SSO cookie set'),
            new OA\Response(response: 404, description: 'Token not found or expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function useAppSsoToken(string $username): Response
    {
        $tokenStr = (string) request()->query('token', '');

        $record = AppSsoToken::redeem($tokenStr, $username);

        if (! $record) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

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
        return $this->callApp(fn () => new JsonResponse(['data' => $this->appManager($username, 'App info')->info()]));
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
        return $this->callApp(fn () => new JsonResponse(['data' => $this->appManager($username, 'App role listing')->listRoles()]));
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
        self::resolveVaultField($request, 'admin_password', $username);
        /** @var array{url: string, title: string, admin_user: string, admin_email: string, admin_password: string} $validated */
        $validated = $request->validate([
            'url' => ['required', 'url'],
            'title' => ['required', 'string'],
            'admin_user' => ['required', 'string'],
            'admin_email' => ['required', 'email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        return $this->callApp(function () use ($username, $validated) {
            $this->appManager($username, 'App install')->install(
                $validated['url'],
                $validated['title'],
                $validated['admin_user'],
                $validated['admin_email'],
                $validated['admin_password'],
            );

            return new JsonResponse(null, 204);
        });
    }

    /** A `vault:<key>` read before validation, so the rules (min:8) see the secret. */
    private static function resolveVaultField(Request $request, string $field, string $username): void
    {
        if ($request->has($field)) {
            $request->merge([$field => RequestVault::resolve($field, $request->input($field), $username)]);
        }
    }
}
