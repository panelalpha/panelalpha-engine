<?php

namespace App\Auth;

use App\Mcp\ToolPolicy;
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
     * @param array<int, string>      $existing the token's current abilities, so
     *                                          anything outside this vocabulary survives
     * @return array<int, string>
     */
    public static function build(
        bool $api,
        bool $mcp,
        ?array $commands = null,
        ?array $routes = null,
        array $existing = []
    ): array {
        $ours = [self::API, self::MCP, self::EVERYTHING];

        // Anything outside this vocabulary belongs to someone else.
        $kept = array_values(array_filter(
            $existing,
            fn (mixed $a): bool => is_string($a)
                && !in_array($a, $ours, true)
                && !str_starts_with($a, self::COMMAND_PREFIX)
                && !str_starts_with($a, self::ROUTE_PREFIX)
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
        $names = $this->commands();

        if ($names === null) {
            return $tools;
        }

        $allowed = array_flip($names);
        $policy = new ToolPolicy();

        return array_values(array_filter(
            $tools,
            fn (string $class): bool => isset($allowed[$policy->nameOf($class)])
        ));
    }

}
