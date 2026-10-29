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

        if (! $token instanceof PersonalAccessToken) {
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

        if (! $abilities->mayUseApi()) {
            return 'This token was not issued for the API. Mint one with `pae api:token:create`.';
        }

        if (! $abilities->mayCallRoute($operation)) {
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
     *
     * @return array<string, mixed>|string|null
     */
    private function projectRefusal(Request $request, TokenAbilities $abilities, ?string $operation): array|string|null
    {
        $scope = $abilities->projects();

        if ($scope === null) {
            return null;
        }

        return $this->routeProjectRefusal($request, $scope, $operation)
            ?? $this->createdProjectRefusal($request, $scope, $operation)
            ?? $this->namedProjectRefusal($request, $scope, $operation)
            ?? $this->operatorRefusal($request, $abilities, $scope, $operation)
            ?? $this->globalProjectRefusal($abilities, $scope, $operation);
    }

    /**
     * What the operator owns on a project in scope: an operation a scoped
     * token may not call, or the fields of one it may not set.
     *
     * @param  array<int, string>  $scope
     */
    private function operatorRefusal(Request $request, TokenAbilities $abilities, array $scope, ?string $operation): ?string
    {
        if (! $abilities->mayCallOperatorRoute($operation)) {
            return sprintf('%s; only an unscoped token may call %s.', $this->scoped($scope), $operation);
        }

        $fields = $abilities->operatorFields($operation, array_keys($request->all()));

        return $fields === [] ? null : sprintf(
            '%s; only an unscoped token may change %s on %s.',
            $this->scoped($scope),
            implode(', ', $fields),
            $operation
        );
    }

    /**
     * The project the route itself names, if any: the path parameter, the
     * project owning a domain, or the task owner.
     *
     * @param  array<int, string>  $scope
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
     * A project the request creates under a name the caller left out: the
     * engine picks that name, and a scope lists names, not a rule for picking.
     *
     * The field goes in `problems`, not the sentence, so a tool call reads it
     * under the argument name the tool takes.
     *
     * @param  array<int, string>  $scope
     * @return array<string, mixed>|null
     */
    private function createdProjectRefusal(Request $request, array $scope, ?string $operation): ?array
    {
        $field = ProjectFields::missingCreationName($request, $operation);

        if ($field === null) {
            return null;
        }

        $message = 'A project-scoped token must name the project this creates.';

        return [
            'error' => sprintf('%s; %s must name the project it creates.', $this->scoped($scope), $operation),
            'errors' => [$field => [$message]],
            'problems' => [['field' => $field, 'code' => $field.'_required', 'message' => $message]],
        ];
    }

    /**
     * Every project named in a body or query field this operation is known to
     * carry, refused as a whole when one is outside the scope.
     *
     * @param  array<int, string>  $scope
     */
    private function namedProjectRefusal(Request $request, array $scope, ?string $operation): ?string
    {
        foreach (ProjectFields::namedIn($request, $operation) as $username) {
            if (! in_array($username, $scope, true)) {
                return $this->outsideScope($scope, $operation);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function globalProjectRefusal(TokenAbilities $abilities, array $scope, ?string $operation): ?string
    {
        return $abilities->mayReachRoute($operation) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * A domain that belongs to a project in scope. A domain outside every
     * scoped project, or one that does not exist, is outside the token.
     *
     * @param  array<int, string>  $scope
     */
    private function domainRefusal(array $scope, string $domain, ?string $operation): ?string
    {
        $username = Domain::findByName($domain)?->user?->username;

        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function taskRefusal(array $scope, int $id, ?string $operation): ?string
    {
        $username = Task::find($id)?->username;

        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function outsideUnless(array $scope, string $username, ?string $operation): ?string
    {
        return in_array($username, $scope, true) ? null : $this->outsideScope($scope, $operation);
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function outsideScope(array $scope, ?string $operation): string
    {
        return sprintf('%s; %s is outside it.', $this->scoped($scope), $operation ?? 'this route');
    }

    /**
     * @param  array<int, string>  $scope
     */
    private function scoped(array $scope): string
    {
        return $scope === []
            ? 'This token is scoped to no project'
            : 'This token is limited to projects '.implode(', ', $scope);
    }

    /**
     * @param  array<string, mixed>|string  $refusal  a message, or a whole body
     */
    private function refuse(array|string $refusal): JsonResponse
    {
        return new JsonResponse(is_string($refusal) ? ['error' => $refusal] : $refusal, 403);
    }
}
