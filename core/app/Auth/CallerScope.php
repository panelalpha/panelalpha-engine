<?php

namespace App\Auth;

use App\Models\PersonalAccessToken;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * What the calling token may hand on. A token it mints or edits stays within it: no surface it lacks, no operation,
 * command or project outside its own limits, no later expiry. It sees and manages only tokens that stay within it,
 * itself included. The console, and anything else that calls without a token, holds everything.
 */
final class CallerScope
{
    /** The parts no request field can fix: the token would reach a surface, or abilities, the caller lacks. */
    private const SURFACES = [TokenAbilities::API, TokenAbilities::MCP, 'everything', 'other'];

    /** @var array<string, string> part => the request field that names it */
    private const FIELDS = ['routes' => 'routes', 'commands' => 'commands', 'projects' => 'projects', 'expires_at' => 'expires_in'];

    private function __construct(
        private readonly TokenAbilities $abilities,
        private readonly ?CarbonInterface $expiresAt,
    ) {}

    public static function of(PersonalAccessToken $token): self
    {
        return new self(TokenAbilities::of($token), $token->expires_at);
    }

    public static function everything(): self
    {
        return new self(TokenAbilities::fromList([TokenAbilities::EVERYTHING]), null);
    }

    public static function forCurrentRequest(): self
    {
        $token = RequestToken::of(request());

        return $token === null ? self::everything() : self::of($token);
    }

    /**
     * Where a token with $abilities, expiring at $expiresAt, would reach past the caller: the parts
     * {@see TokenAbilities::widerThan()} names, and `expires_at` when it would outlive an expiring caller.
     *
     * @param  array<int, string>  $abilities
     * @return list<string>
     */
    public function exceeded(array $abilities, ?CarbonInterface $expiresAt): array
    {
        $wider = TokenAbilities::fromList($abilities)->widerThan($this->abilities);

        if ($this->expiresAt !== null && ($expiresAt === null || $expiresAt->greaterThan($this->expiresAt))) {
            $wider[] = 'expires_at';
        }

        return $wider;
    }

    public function covers(PersonalAccessToken $token): bool
    {
        return $this->exceeded(is_array($token->abilities) ? $token->abilities : [], $token->expires_at) === [];
    }

    /** @throws AuthorizationException when the caller does not cover $token */
    public function mayManage(PersonalAccessToken $token): void
    {
        if (! $this->covers($token)) {
            throw new AuthorizationException('That token reaches further than yours, so yours may not see or change it.');
        }
    }

    /**
     * @param  array<int, string>  $abilities
     *
     * @throws AuthorizationException when the token would reach a surface the caller lacks
     * @throws ValidationException when it would name more than the caller's own limits, or outlive it
     */
    public function mayGrant(array $abilities, ?CarbonInterface $expiresAt): void
    {
        $exceeded = $this->exceeded($abilities, $expiresAt);

        if (array_intersect($exceeded, self::SURFACES) !== []) {
            throw new AuthorizationException('Your token may not hand on a surface or an ability it does not have itself.');
        }

        if ($exceeded !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(static fn (string $part): string => self::FIELDS[$part], $exceeded),
                array_map($this->why(...), $exceeded),
            ));
        }
    }

    private function why(string $part): string
    {
        return match ($part) {
            'routes' => 'Your token is limited to part of the API; name only operations it may call itself: '.implode(', ', $this->abilities->routes() ?? []).'.',
            'commands' => 'Your token is limited to some MCP commands; name only commands it may call itself: '.implode(', ', $this->abilities->commands() ?? []).'.',
            'projects' => 'Your token reaches only some projects; name only those: '.implode(', ', $this->abilities->projects() ?? []).'.',
            default => sprintf('Your token expires at %s; give a lifetime that ends by then.', $this->expiresAt?->toIso8601String()),
        };
    }
}
