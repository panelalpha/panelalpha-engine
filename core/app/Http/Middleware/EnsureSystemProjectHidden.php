<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Auth\ProjectFields;
use App\Auth\RequestToken;
use App\Auth\SystemProjects;
use App\Models\Domain;
use App\Models\Task;
use App\Models\WordpressMigration;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * A system project is answered as missing to every token but its own, whichever way a request names it: the path,
 * a domain, a task, a migration or a body field. The answer is the one a route gives for a name that does not exist.
 */
class EnsureSystemProjectHidden
{
    /** Path parameters that are a project's username. */
    private const PROJECT_PARAMETERS = ['username', 'project', 'owner'];

    /**
     * Operations whose project field is not a project that must exist: a list filter, which leaves the hidden
     * project's rows out on its own, and the name of a project being created.
     */
    private const UNCHECKED_FIELDS = [
        'GET /tasks',
        'POST /projects/{username}/clone',
        'POST /projects/{username}/staging',
    ];

    private const PROJECT_MISSING = 'Project not found';

    private const RECORD_MISSING = 'Not found';

    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();
        if (RequestToken::of($request) === null || ! $route instanceof Route) {
            return $next($request);
        }

        $visibility = SystemProjects::forRequest($request);
        if ($visibility->hidden() === []) {
            return $next($request);
        }

        return $this->missingAnswer($visibility, $request, $route) ?? $next($request);
    }

    private function missingAnswer(SystemProjects $visibility, Request $request, Route $route): ?JsonResponse
    {
        foreach ($this->projectsNamedBy($request, $route) as $username) {
            if ($visibility->hides($username)) {
                return self::missing(self::PROJECT_MISSING);
            }
        }
        if ($route->hasParameter('domain') && $visibility->hides($this->ownerOfDomain($route))) {
            return self::missing(self::RECORD_MISSING);
        }
        if ($route->hasParameter('id') && $visibility->hides($this->ownerOfRecord($route))) {
            return self::missing(self::RECORD_MISSING);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function projectsNamedBy(Request $request, Route $route): array
    {
        $operation = ApiSurface::keyFor($route, $request->method());
        $named = in_array($operation, self::UNCHECKED_FIELDS, true) ? [] : ProjectFields::namedIn($request, $operation);
        foreach (self::PROJECT_PARAMETERS as $parameter) {
            if ($route->hasParameter($parameter)) {
                $named[] = (string) $route->parameter($parameter);
            }
        }

        return $named;
    }

    private function ownerOfDomain(Route $route): ?string
    {
        return Domain::findByName((string) $route->parameter('domain'))?->user?->username;
    }

    private function ownerOfRecord(Route $route): ?string
    {
        $id = (int) $route->parameter('id');

        return match (true) {
            str_starts_with($route->uri(), 'api/tasks/') => Task::find($id)?->username,
            str_starts_with($route->uri(), 'api/wordpress/migrations/') => WordpressMigration::find($id)?->username,
            default => null,
        };
    }

    private static function missing(string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message], 404);
    }
}
