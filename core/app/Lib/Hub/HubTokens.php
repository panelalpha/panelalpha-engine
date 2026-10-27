<?php

namespace App\Lib\Hub;

use App\Models\Admin;
use App\Models\PersonalAccessToken;
use Laravel\Sanctum\NewAccessToken;

/**
 * The Sanctum tokens the Hub holds, on the root admin: `hub:pending` while
 * the connect ceremony runs, `hub:<connection_id>` once it completed. Any
 * token creator can pick such a name, so a Hub token is told by its `hub`
 * mark, which only minting here sets and a rotation carries over.
 */
final class HubTokens
{
    public const NAME_PREFIX = 'hub:';

    public const PENDING = 'hub:pending';

    public const LIFETIME_DAYS = 30;

    /** @param array<int, string> $abilities */
    public static function mint(array $abilities, string $name = self::PENDING): NewAccessToken
    {
        $new = Admin::rootAccount()->createToken($name, $abilities, now()->addDays(self::LIFETIME_DAYS));
        $new->accessToken->forceFill(['hub' => true])->save();

        return $new;
    }

    public static function nameFor(string $connectionId): string
    {
        return self::NAME_PREFIX.$connectionId;
    }

    public static function isHubToken(?PersonalAccessToken $token): bool
    {
        return $token?->hub === true;
    }

    /** The connection a token was issued for, or null for a pending or foreign one. */
    public static function connectionIdOf(?PersonalAccessToken $token): ?string
    {
        if (! self::isHubToken($token) || $token->name === self::PENDING) {
            return null;
        }

        return substr((string) $token->name, strlen(self::NAME_PREFIX));
    }

    public static function revoke(int $id): void
    {
        PersonalAccessToken::query()
            ->whereKey($id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** @return int how many were revoked */
    public static function revokeAll(?int $except = null): int
    {
        return PersonalAccessToken::query()
            ->where('hub', true)
            ->whereNull('revoked_at')
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->update(['revoked_at' => now()]);
    }
}
