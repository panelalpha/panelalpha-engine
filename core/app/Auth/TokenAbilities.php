<?php

namespace App\Auth;

use App\Lib\Limits\ResourceLimit;
use App\Mcp\ToolPolicy;
use App\Mcp\Tools\Api\ApiTool;
use App\Models\PersonalAccessToken;
use Laravel\Mcp\Server\Tool;

/**
 * What a token is allowed to do, read off its Sanctum abilities. The only
 * class that knows this vocabulary; both middlewares ask it.
 *
 * `api` and `mcp` are the two surfaces, `mcp:<command>` and `api:<VERB /path>`
 * narrow one. No prefixed ability means no limit. `*` and an empty list both
 * mean everything — which is every token minted before this existed.
 */
class TokenAbilities
{
    public const API = 'api';
    public const MCP = 'mcp';

    /** Sanctum's own "everything": both surfaces, no limit. */
    public const EVERYTHING = '*';

    /** `mcp:project_list` */
    public const COMMAND_PREFIX = 'mcp:';

    /** `api:GET /projects/{username}` */
    public const ROUTE_PREFIX = 'api:';

    /** `project:alice` */
    public const PROJECT_PREFIX = 'project:';

    /**
     * Marks a token as project-scoped. A scope can be empty (the token reaches
     * no project), which its `project:` entries alone could not express:
     * `projects()` reads this marker to tell that apart from an unscoped token.
     */
    public const PROJECT_SCOPE = 'project';

    /**
     * Operations a project-scoped token may still reach without naming a
     * project in its path: the project lists (filtered in the controller),
     * the tasks of its projects, and the two reads that are about the engine,
     * not about a project.
     */
    private const PROJECT_SCOPE_ROUTES = [
        'GET /projects',
        'GET /projects/all',
        'GET /tasks',
        'GET /tasks/{id}',
        'GET /tasks/{id}/logs',
        'GET /tasks/{id}/logs/stream',
        'POST /tasks/{id}/cancel',
        'GET /system/info',
        'POST /source/inspect',
    ];

    /**
     * Operations on a project that are the operator's, not the project's: a
     * project-scoped token reaches its project, never these.
     */
    private const OPERATOR_ROUTES = [
        'PUT /projects/{username}/suspend',
        'PUT /projects/{username}/unsuspend',
    ];

    /** The project update, whose limits and runtime sizing are the operator's. */
    private const PROJECT_UPDATE = 'PUT /projects/{username}';

    /** The runtime sizing the project update takes beside the resource limits. */
    private const RUNTIME_SIZING = ['php_fpm_pool_settings', 'lsphp_settings', 'redis_config'];

    /** Hand-written tools that answer a project list and are filtered in their own class. */
    private const PROJECT_SCOPE_TOOLS = ['project_list_summary'];

    /** @param array<int, string> $abilities */
    private function __construct(private readonly array $abilities)
    {
    }

    /** @param array<int, string> $abilities */
    public static function fromList(array $abilities): self
    {
        return new self(array_values(array_filter($abilities, 'is_string')));
    }

    public static function of(PersonalAccessToken $token): self
    {
        return self::fromList(is_array($token->abilities) ? $token->abilities : []);
    }

    /**
     * Not a fail-open: both surfaces authenticate first, so what gets here
     * without a token is the console asking about the engine, not a caller.
     */
    public static function forCurrentRequest(): self
    {
        $token = request()?->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken
            ? self::of($token)
            : self::fromList([self::EVERYTHING]);
    }

    /**
     * @param array<int, string>|null $commands MCP commands to allow, or null for all
     * @param array<int, string>|null $routes   REST operations to allow, or null for all
     * @param array<int, string>|null $projects project usernames to allow, or null for all
     * @param array<int, string>      $existing the token's current abilities, so
     *                                          anything outside this vocabulary survives
     * @return array<int, string>
     */
    public static function build(
        bool $api,
        bool $mcp,
        ?array $commands = null,
        ?array $routes = null,
        array $existing = [],
        ?array $projects = null
    ): array {
        $ours = [self::API, self::MCP, self::EVERYTHING, self::PROJECT_SCOPE];

        // Anything outside this vocabulary belongs to someone else.
        $kept = array_values(array_filter(
            $existing,
            fn (mixed $a): bool => is_string($a)
                && !in_array($a, $ours, true)
                && !str_starts_with($a, self::COMMAND_PREFIX)
                && !str_starts_with($a, self::ROUTE_PREFIX)
                && !str_starts_with($a, self::PROJECT_PREFIX)
        ));

        $built = [];

        if ($api) {
            $built[] = self::API;
        }

        if ($mcp) {
            $built[] = self::MCP;
        }

        // Only for a surface the token has, so it cannot name operations on a
        // door it is refused at.
        if ($mcp && $commands !== null) {
            $built = array_merge($built, self::prefixed(self::COMMAND_PREFIX, $commands));
        }

        if ($api && $routes !== null) {
            $built = array_merge($built, self::prefixed(self::ROUTE_PREFIX, $routes));
        }

        if ($projects !== null) {
            $built[] = self::PROJECT_SCOPE;
            $built = array_merge($built, self::prefixed(self::PROJECT_PREFIX, $projects));
        }

        return array_values(array_merge($kept, $built));
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private static function prefixed(string $prefix, array $values): array
    {
        $values = array_values(array_unique(array_filter($values)));
        sort($values);

        return array_map(fn (string $v): string => $prefix . $v, $values);
    }

    /** A token from before any of this: `*`, or nothing at all. */
    public function isLegacy(): bool
    {
        return $this->abilities === [] || in_array(self::EVERYTHING, $this->abilities, true);
    }

    /**
     * A token that neither limits operations nor limits projects. Only such a
     * token may ask for every project's tasks at once.
     */
    public function isUnrestricted(): bool
    {
        return $this->isLegacy() || ($this->routes() === null && $this->projects() === null);
    }

    /**
     * The project usernames this token may reach, or null for every project.
     *
     * @return array<int, string>|null
     */
    public function projects(): ?array
    {
        $named = $this->named(self::PROJECT_PREFIX);

        if ($named !== null) {
            return $named;
        }

        return in_array(self::PROJECT_SCOPE, $this->abilities, true) ? [] : null;
    }

    /** A token limited to some projects, whatever its surface or route limit. */
    public function isProjectScoped(): bool
    {
        return $this->projects() !== null;
    }

    /** Whether this token may reach a project the request names. */
    public function mayReachProject(?string $username): bool
    {
        $projects = $this->projects();

        return $projects === null
            || ($username !== null && in_array($username, $projects, true));
    }

    /**
     * Whether an operation is reachable at all. Which project a `{username}`
     * or `{domain}` route reaches is decided per call instead; here it is
     * refused only when it is the operator's.
     */
    public function mayReachRoute(?string $operation): bool
    {
        if (!$this->isProjectScoped()) {
            return true;
        }

        return $operation !== null
            && $this->mayCallOperatorRoute($operation)
            && (str_contains($operation, '{username}')
                || str_contains($operation, '{domain}')
                || in_array($operation, self::PROJECT_SCOPE_ROUTES, true));
    }

    /** Whether this token may call an operation the operator owns. Only an unscoped one may. */
    public function mayCallOperatorRoute(?string $operation): bool
    {
        return !$this->isProjectScoped() || !in_array($operation, self::OPERATOR_ROUTES, true);
    }

    /**
     * The fields among those a request sends that are the operator's and this
     * token may not set. None for an unscoped token.
     *
     * @param array<int, int|string> $sent
     * @return list<string>
     */
    public function operatorFields(?string $operation, array $sent): array
    {
        if (!$this->isProjectScoped() || $operation !== self::PROJECT_UPDATE) {
            return [];
        }

        return array_values(array_intersect(self::operatorOwnedUpdateFields(), $sent));
    }

    /** @return list<string> */
    private static function operatorOwnedUpdateFields(): array
    {
        return [...ResourceLimit::keys(), ...self::RUNTIME_SIZING];
    }

    /** A named operation implies the surface it is named on. */
    public function mayUseApi(): bool
    {
        return $this->isLegacy()
            || in_array(self::API, $this->abilities, true)
            || $this->routes() !== null;
    }

    /** A named command implies the surface it is named on. */
    public function mayUseMcp(): bool
    {
        return $this->isLegacy()
            || in_array(self::MCP, $this->abilities, true)
            || $this->commands() !== null;
    }

    /** @return array<int, string>|null null when not limited */
    public function commands(): ?array
    {
        return $this->named(self::COMMAND_PREFIX);
    }

    /**
     * Each is `VERB /path` as {@see ApiSurface} spells one.
     *
     * @return array<int, string>|null null when not limited
     */
    public function routes(): ?array
    {
        return $this->named(self::ROUTE_PREFIX);
    }

    /** Whether this token may call one REST operation. */
    public function mayCallRoute(?string $key): bool
    {
        $allowed = $this->routes();

        if ($allowed === null) {
            return true;
        }

        // An unrecognised route cannot be on an allow-list, so refuse it.
        return $key !== null && in_array($key, $allowed, true);
    }

    /**
     * @return array<int, string>|null
     */
    private function named(string $prefix): ?array
    {
        $names = [];

        foreach ($this->abilities as $ability) {
            if (str_starts_with($ability, $prefix)) {
                $names[] = substr($ability, strlen($prefix));
            }
        }

        return $names === [] ? null : array_values(array_unique($names));
    }

    /**
     * This token's abilities with its MCP command limit replaced.
     *
     * @param array<int, string>|null $commands
     * @return array<int, string>
     */
    public function withCommands(?array $commands): array
    {
        return self::build(
            api: $this->mayUseApi(),
            mcp: true,
            commands: $commands,
            routes: $this->routes(),
            existing: $this->abilities,
            projects: $this->projects(),
        );
    }

    /**
     * This token's abilities with its REST operation limit replaced.
     *
     * @param array<int, string>|null $routes
     * @return array<int, string>
     */
    public function withRoutes(?array $routes): array
    {
        return self::build(
            api: true,
            mcp: $this->mayUseMcp(),
            commands: $this->commands(),
            routes: $routes,
            existing: $this->abilities,
            projects: $this->projects(),
        );
    }

    /**
     * This token's abilities with its project scope replaced. Null removes the
     * scope; an empty list limits the token to no project at all.
     *
     * @param array<int, string>|null $projects
     * @return array<int, string>
     */
    public function withProjects(?array $projects): array
    {
        return self::build(
            api: $this->mayUseApi(),
            mcp: $this->mayUseMcp(),
            commands: $this->commands(),
            routes: $this->routes(),
            existing: $this->abilities,
            projects: $projects,
        );
    }

    /** What this token is, in a word, for a listing. */
    public function label(): string
    {
        return match (true) {
            $this->isLegacy() => 'everything',
            $this->mayUseApi() && $this->mayUseMcp() => 'api + assistant',
            $this->mayUseApi() => 'api',
            $this->mayUseMcp() => 'assistant',
            default => 'nothing',
        };
    }

    /**
     * @param array<int, class-string<Tool>> $tools
     * @return array<int, class-string<Tool>>
     */
    public function filterTools(array $tools): array
    {
        $commands = $this->commands();

        if ($commands === null && !$this->isProjectScoped()) {
            return $tools;
        }

        $allowed = $commands === null ? null : array_flip($commands);
        $policy = new ToolPolicy();

        return array_values(array_filter(
            $tools,
            fn (string $class): bool => $this->permitsTool($policy, $class, $allowed)
        ));
    }

    /**
     * @param array<string, int>|null $allowed
     * @param class-string<Tool>      $class
     */
    private function permitsTool(ToolPolicy $policy, string $class, ?array $allowed): bool
    {
        $name = $policy->nameOf($class);

        if ($allowed !== null && !isset($allowed[$name])) {
            return false;
        }

        if (!$this->isProjectScoped()) {
            return true;
        }

        $tool = new $class();

        return $tool instanceof ApiTool
            ? $this->mayReachRoute($tool->operationKey())
            : in_array($name, self::PROJECT_SCOPE_TOOLS, true);
    }

}
