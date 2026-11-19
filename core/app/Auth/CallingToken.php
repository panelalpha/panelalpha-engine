<?php

namespace App\Auth;

use App\Lib\Hub\HubLifecycle;
use App\Lib\Project\ApiAccess;
use App\Models\PersonalAccessToken;
use App\Models\User;

/** What a token may do to itself, with the Hub connection it holds kept in step. */
final class CallingToken
{
    /**
     * @return array{token: string, token_id: int, abilities: array<int, string>, expires_at: ?string}
     */
    public static function rotate(PersonalAccessToken $token): array
    {
        $credential = TokenRotation::rotate($token);
        HubLifecycle::tokenRotated($token, $credential['token_id']);

        return $credential;
    }

    /**
     * The token's own id and expiry, and the project whose API access holds it.
     *
     * @return array{token_id: int, project: ?string, system: bool, expires_at: ?string}
     */
    public static function describe(PersonalAccessToken $token): array
    {
        $project = app(ApiAccess::class)->projectHolding($token);

        return [
            'token_id' => (int) $token->getKey(),
            'project' => $project,
            'system' => $project !== null && (User::findByUsername($project)?->isSystem() ?? false),
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];
    }

    public static function revoke(PersonalAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
        HubLifecycle::tokenRevoked($token);
    }
}
