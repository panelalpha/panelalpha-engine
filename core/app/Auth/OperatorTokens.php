<?php

namespace App\Auth;

use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * The operator's own tokens of one kind: `api` for software, `mcp` for assistants. Minted, narrowed, revoked and
 * deleted the same way by `pae configure` and the API, always within the caller's own token: {@see CallerScope}.
 */
class OperatorTokens
{
    private const LIFETIME = '/^(\d+)\s*([smhdw])$/i';

    public function __construct(
        private readonly string $ability,
        private readonly CallerScope $caller,
    ) {}

    /** The caller defaults to the token of the current request; the console has none and holds everything. */
    public static function api(?CallerScope $caller = null): self
    {
        return new self(TokenAbilities::API, $caller ?? CallerScope::forCurrentRequest());
    }

    public static function mcp(?CallerScope $caller = null): self
    {
        return new self(TokenAbilities::MCP, $caller ?? CallerScope::forCurrentRequest());
    }

    /**
     * The tokens of this kind the caller covers, oldest first. A token from before the split carries `*` and
     * satisfies both kinds, so it is listed under both.
     *
     * @return list<PersonalAccessToken>
     */
    public function all(): array
    {
        $tokens = [];
        foreach (Admin::rootAccount()->tokens()->orderBy('id')->get() as $token) {
            if ($token instanceof PersonalAccessToken && $this->isOfThisKind($token) && $this->caller->covers($token)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @throws ModelNotFoundException when the root account has no token of this kind under $id
     * @throws AuthorizationException when the caller does not cover it
     */
    public function find(int $id): PersonalAccessToken
    {
        $token = Admin::rootAccount()->tokens()->whereKey($id)->first();

        if (! $token instanceof PersonalAccessToken || ! $this->isOfThisKind($token)) {
            throw (new ModelNotFoundException)->setModel(PersonalAccessToken::class, [$id]);
        }

        $this->caller->mayManage($token);

        return $token;
    }

    /** Whether the caller may see and change $token. */
    public function covers(PersonalAccessToken $token): bool
    {
        return $this->caller->covers($token);
    }

    /**
     * A new token of this kind; its plaintext is in the result and nowhere else.
     *
     * @param  ?string  $lifetime  a number and a unit (30m, 12h, 90d, 2w), or null for a token that never expires
     * @param  list<string>|null  $projects  the projects it may reach, or null for every project
     * @param  list<string>|null  $limit  the REST operations or MCP commands it may call, or null for all
     *
     * @throws AuthorizationException|ValidationException when it would reach past the caller
     */
    public function mint(string $name, ?string $lifetime = null, ?array $projects = null, ?array $limit = null): NewAccessToken
    {
        $api = $this->ability === TokenAbilities::API;
        $abilities = TokenAbilities::build(
            api: $api,
            mcp: ! $api,
            commands: $api ? null : $limit,
            routes: $api ? $limit : null,
            projects: $projects,
        );
        $expiresAt = $lifetime === null || trim($lifetime) === '' ? null : self::expiry($lifetime);

        $this->caller->mayGrant($abilities, $expiresAt);

        return Admin::rootAccount()->createToken($name, $abilities, $expiresAt);
    }

    /**
     * Limits what the token may call: REST operations for an `api` token, MCP commands for an `mcp` one. Null
     * lifts the limit.
     *
     * @param  list<string>|null  $keys
     */
    public function limit(PersonalAccessToken $token, ?array $keys): PersonalAccessToken
    {
        $abilities = TokenAbilities::of($token);

        return $this->change($token, $this->ability === TokenAbilities::API ? $abilities->withRoutes($keys) : $abilities->withCommands($keys));
    }

    /**
     * Replaces the projects the token may reach; null removes the scope, an empty list leaves it no project.
     *
     * @param  list<string>|null  $projects
     */
    public function scope(PersonalAccessToken $token, ?array $projects): PersonalAccessToken
    {
        return $this->change($token, TokenAbilities::of($token)->withProjects($projects));
    }

    /** Refused everywhere at once; it stays listed, and cannot be un-revoked. */
    public function revoke(PersonalAccessToken $token): PersonalAccessToken
    {
        $this->caller->mayManage($token);
        $token->forceFill(['revoked_at' => Carbon::now()])->save();

        return $token;
    }

    public function delete(PersonalAccessToken $token): void
    {
        $this->caller->mayManage($token);
        $token->delete();
    }

    /** What the token may call of this kind's surface, null when it is not limited. */
    public function limitOf(PersonalAccessToken $token): ?array
    {
        $abilities = TokenAbilities::of($token);

        return $this->ability === TokenAbilities::API ? $abilities->routes() : $abilities->commands();
    }

    /** @return array<string, mixed> */
    public function toArray(PersonalAccessToken $token): array
    {
        $abilities = TokenAbilities::of($token);

        return [
            'id' => $token->id,
            'name' => $token->name,
            $this->ability === TokenAbilities::API ? 'routes' : 'commands' => $this->limitOf($token),
            'projects' => $abilities->projects(),
            'legacy' => $abilities->isLegacy(),
            'expires_at' => $token->expires_at,
            'last_used_at' => $token->last_used_at,
            'revoked_at' => $token->revoked_at,
            'created_at' => $token->created_at,
        ];
    }

    public static function badLifetime(string $lifetime): ?string
    {
        return trim($lifetime) === '' || preg_match(self::LIFETIME, trim($lifetime)) === 1
            ? null
            : 'Give a number and a unit, like 90d — or leave it empty.';
    }

    public static function expiry(string $lifetime): Carbon
    {
        preg_match(self::LIFETIME, trim($lifetime), $m);

        return Carbon::now()->add(match (strtolower($m[2])) {
            's' => 'seconds',
            'm' => 'minutes',
            'h' => 'hours',
            'w' => 'weeks',
            default => 'days',
        }, (int) $m[1]);
    }

    /** @param  array<int, string>  $abilities */
    private function change(PersonalAccessToken $token, array $abilities): PersonalAccessToken
    {
        $this->caller->mayManage($token);
        $this->caller->mayGrant($abilities, $token->expires_at);
        $token->forceFill(['abilities' => $abilities])->save();

        return $token;
    }

    private function isOfThisKind(PersonalAccessToken $token): bool
    {
        $abilities = TokenAbilities::of($token);

        return $this->ability === TokenAbilities::API ? $abilities->mayUseApi() : $abilities->mayUseMcp();
    }
}
