<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Auth\ProjectFields;
use App\Auth\RequestToken;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ContainerOperations;
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

    /**
     * Project-level compose actions that leave the app down or restart it. A
     * pull recreates every service whose image changed.
     */
    private const STOPPING_ACTIONS = ['stop', 'down', 'restart', 'pull'];

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
        'POST /projects/{username}/backups/{id}/restore',
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
        if ($operation === null || ! in_array($operation, self::REFUSED, true)) {
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
        if (! $this->actsOnProject($request, $operation)) {
            return [];
        }

        if (ProjectFields::named($operation) !== []) {
            return ProjectFields::namedIn($request, $operation);
        }

        $username = (string) $request->route('username');

        return $username === '' ? [] : [$username];
    }

    /**
     * Whether this request is the refused mode of a conditional operation. The
     * rest are refused whatever they carry.
     */
    private function actsOnProject(Request $request, string $operation): bool
    {
        return match ($operation) {
            'POST /projects/{username}/containers/action' => $this->refusesProjectAction($request),
            'POST /projects/{username}/containers/{service}/action' => in_array($request->input('action'), self::STOPPING_SERVICE_ACTIONS, true),
            'PUT /projects/{username}/env-vars' => $request->input('apply', 'recreate') !== 'none',
            'PUT /projects/{username}' => $request->filled('domain'),
            'DELETE /projects/{username}/domains/{domain}' => $this->deletesMainDomain($request),
            default => true,
        };
    }

    /**
     * A project-level action is refused when it stops the app, or when `up`
     * rewrites the run file from the compose file on disk first, which starts
     * the app on whatever that now holds, stored environment included.
     */
    private function refusesProjectAction(Request $request): bool
    {
        $action = $request->input('action');
        if (in_array($action, self::STOPPING_ACTIONS, true)) {
            return true;
        }

        return $action === 'up' && $this->upRegeneratesRunFile((string) $request->route('username'));
    }

    private function upRegeneratesRunFile(string $username): bool
    {
        $runtime = User::findByUsername($username)?->project(app(System::class))->runtime();

        return $runtime instanceof Dind
            && ContainerOperations::actionRegeneratesRunFile($runtime, 'up');
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
                .'redeploying it needs an operator: clear the setting first with '
                ."`pae project:settings:unset protected --project={$username}`.",
            'code' => self::CODE,
        ], 409);
    }
}
