<?php

namespace App\Http\Middleware;

use App\Auth\ApiSurface;
use App\Auth\ProjectFields;
use App\Auth\RequestToken;
use App\Auth\TokenAbilities;
use App\Mcp\Tools\Api\ApiTool;
use App\Models\Domain;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a token may do on the REST API. `auth:api` calls `tokenCan` nowhere, so
 * before this a token issued for anything reached every route on the engine.
 *
 * A call is authorised by the door it arrived at, so this asks nothing about
 * MCP. {@see ApiTool} dispatches tool calls through this router in-process
 * and marks them on the `attributes` bag — server-side, never populated from
 * an inbound request, so only this engine can claim it.
 *
 * A project-limited token is asked about at both doors: a tool call carries
 * the project it names, so skipping it here would let a scoped assistant
 * reach another project's MCP tools.
 */
class EnsureTokenMayUseApi
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = RequestToken::of($request);

        if (!$token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $abilities = TokenAbilities::of($token);
        $operation = ApiSurface::keyFor($request->route(), $request->method());

        $refusal = $this->doorRefusal($request, $abilities, $operation)
            ?? $this->projectRefusal($request, $abilities, $operation);

        return $refusal === null ? $next($request) : $this->refuse($refusal);
    }

    /**
     * The token's own limits at the door it arrived at.
     *
     * A tool call was already measured at `/mcp`, and re-deciding it against a
     * REST allow-list it was never given would refuse every tool on the engine.
     */
    private function doorRefusal(Request $request, TokenAbilities $abilities, ?string $operation): ?string
    {
        if ($request->attributes->get(ApiTool::VIA_ATTRIBUTE) === true) {
            return null;
        }

        if (!$abilities->mayUseApi()) {
            return 'This token was not issued for the API. Mint one with `pae api:token:create`.';
        }

        if (!$abilities->mayCallRoute($operation)) {
            return sprintf(
                'This token is limited to part of the API, and %s is not in it.',
                $operation ?? 'this route'
            );
        }

        return null;
    }

    /**
     * Why a project-scoped token may not reach this request, or null when it
     * names no project, or every project it names is in scope.
     */
    private function projectRefusal(Request $request, TokenAbilities $abilities, ?string $operation): ?string
    {
        $scope = $abilities->projects();

        if ($scope === null) {
            return null;
        }

        return $this->routeProjectRefusal($request, $scope, $operation)
            ?? $this->namedProjectRefusal($request, $scope, $operation)
            ?? $this->globalProjectRefusal($abilities, $scope, $operation);
    }

    /**
     * The project the route itself names, if any: the path parameter, the
     * project owning a domain, or the task owner.
     *
     * @param array<int, string> $scope
     */
    private function routeProjectRefusal(Request $request, array $scope, ?string $operation): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        if ($route->hasParameter('username')) {
            return $this->outsideUnless($scope, (string) $route->parameter('username'), $operation);
        }

        if ($route->hasParameter('domain')) {
            return $this->domainRefusal($scope, (string) $route->parameter('domain'), $operation);
        }

        if (str_starts_with($route->uri(), 'api/tasks/')) {
            return $this->taskRefusal($scope, (int) $route->parameter('id'), $operation);
        }

        return null;
    }

    /**
     * Every project named in a body or query field this operation is known to
     * carry, refused as a whole when one is outside the scope.
     *
     * @param array<int, string> $scope
     */
    private function namedProjectRefusal(Request $request, array $scope, ?string $operation): ?string
    {
        foreach (ProjectFields::named($operation) as $field) {
            $value = $request->input($field);

            if (is_string($value) && $value !== '' && !in_array($value, $scope, true)) {
                return $this->outsideScope($scope, $operation);
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $scope
     */
    private function globalProjectRefusal(TokenAbilities $abilities, array $scope, ?string $operation): ?string
    {
        return $abilities->mayReachRoute($operation) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * A domain that belongs to a project in scope. A domain outside every
     * scoped project, or one that does not exist, is outside the token.
     *
     * @param array<int, string> $scope
     */
    private function domainRefusal(array $scope, string $domain, ?string $operation): ?string
    {
        $username = Domain::findByName($domain)?->user?->username;

        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param array<int, string> $scope
     */
    private function taskRefusal(array $scope, int $id, ?string $operation): ?string
    {
        $username = Task::find($id)?->username;

        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param array<int, string> $scope
     */
    private function outsideUnless(array $scope, string $username, ?string $operation): ?string
    {
        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param array<int, string> $scope
     */
    private function outsideScope(array $scope, ?string $operation): string
    {
        return sprintf(
            'This token is limited to projects %s; %s is outside it.',
            implode(', ', $scope),
            $operation ?? 'this route'
        );
    }

    private function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 403);
    }
}
