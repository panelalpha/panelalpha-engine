<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Auth\ProjectFields;
use App\Auth\RequestToken;
use App\Models\Domain;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The `protected` project setting: while it is on, a call made with an API or
 * MCP token may not delete, stop or redeploy that project.
 *
 * Only a token is refused. The operator's own `pae` commands dispatch routes
 * in-process with no token, and a deploy hook delivery carries none either, so
 * neither reaches this middleware.
 */
class EnsureProjectNotProtected
{
    /** The stable code a refusal carries, beside the message. */
    public const CODE = 'project_protected';

    /** Project-level compose actions that leave the app down or restart it. */
    private const STOPPING_ACTIONS = ['stop', 'down', 'restart'];

    /** Service-level docker actions that stop the app. */
    private const STOPPING_SERVICE_ACTIONS = ['stop', 'restart'];

    /**
     * Operations refused for a protected project, by the key ApiSurface::keyFor()
     * produces. Some are refused in one mode only -- a container `up`, an
     * `apply: none`, a push whose target is another project -- and
     * actsOnProject() tells those apart.
     *
     * @var list<string>
     */
    private const REFUSED = [
        'DELETE /projects/{username}',
        'PUT /projects/{username}',
        'PUT /projects/{username}/suspend',
        'POST /projects/{username}/rebuild',
        'POST /projects/{username}/deploy-archive',
        'POST /projects/{username}/source',
        'POST /projects/{username}/git/pull',
        'PUT /projects/{username}/git/change-branch',
        'POST /projects/{username}/git/revert',
        'PUT /projects/{username}/env-vars',
        'POST /projects/{username}/containers/action',
        'POST /projects/{username}/containers/{service}/action',
        'DELETE /projects/{username}/domains/{domain}',
        'POST /projects/{username}/push',
        'POST /projects/{username}/staging/retry',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        if (RequestToken::of($request) === null) {
            return $next($request);
        }

        $operation = ApiSurface::keyFor($request->route(), $request->method());
        $username = $this->protectedTarget($request, $operation);

        return $username === null ? $next($request) : $this->refuse($username);
    }

    /**
     * The protected project this request acts on, or null when it acts on none,
     * or on one that is not protected.
     */
    private function protectedTarget(Request $request, ?string $operation): ?string
    {
        if ($operation === null || !in_array($operation, self::REFUSED, true)) {
            return null;
        }

        foreach ($this->targets($request, $operation) as $username) {
            if (User::findByUsername($username)?->isProtected() === true) {
                return $username;
            }
        }

        return null;
    }

    /**
     * The projects the request acts on: the ones it names in a body field, or
     * the route's own project.
     *
     * @return list<string>
     */
    private function targets(Request $request, string $operation): array
    {
        if (!$this->actsOnProject($request, $operation)) {
            return [];
        }

        $fields = ProjectFields::named($operation);
        if ($fields !== []) {
            return self::namedTargets($request, $fields);
        }

        $username = (string) $request->route('username');

        return $username === '' ? [] : [$username];
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private static function namedTargets(Request $request, array $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Whether this request is the refused mode of a conditional operation. The
     * rest are refused whatever they carry.
     */
    private function actsOnProject(Request $request, string $operation): bool
    {
        return match ($operation) {
            'POST /projects/{username}/containers/action' => in_array($request->input('action'), self::STOPPING_ACTIONS, true),
            'POST /projects/{username}/containers/{service}/action' => in_array($request->input('action'), self::STOPPING_SERVICE_ACTIONS, true),
            'PUT /projects/{username}/env-vars' => $request->input('apply', 'recreate') !== 'none',
            'PUT /projects/{username}' => $request->filled('domain'),
            'DELETE /projects/{username}/domains/{domain}' => $this->deletesMainDomain($request),
            default => true,
        };
    }

    /**
     * Deleting the main domain renames the project itself. Removing any other
     * domain of it is not something the setting guards.
     */
    private function deletesMainDomain(Request $request): bool
    {
        $domain = Domain::findByName((string) $request->route('domain'));

        return $domain !== null
            && $domain->type === 'main'
            && $domain->user?->username === (string) $request->route('username');
    }

    private function refuse(string $username): JsonResponse
    {
        return new JsonResponse([
            'message' => "Project '{$username}' is protected from API and MCP tokens. Deleting, stopping or "
                . 'redeploying it needs an operator: clear the setting first with '
                . "`pae project:settings:unset protected --project={$username}`.",
            'code' => self::CODE,
        ], 409);
    }
}
