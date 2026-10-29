<?php

namespace App\Auth;

use App\Lib\Hub\HubLifecycle;
use App\Models\PersonalAccessToken;

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

    public static function revoke(PersonalAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->save();
        HubLifecycle::tokenRevoked($token);
    }
}
