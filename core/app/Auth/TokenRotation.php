<?php

namespace App\Auth;

use App\Models\PersonalAccessToken;
use App\Models\TokenRotation as Rotation;
use Illuminate\Support\Facades\DB;

/**
 * Replace a token with a new one of the same name and abilities. The old one
 * keeps working for a grace period, and rotating it again within that period
 * returns the same new token rather than minting a third.
 */
final class TokenRotation
{
    public const LIFETIME_DAYS = 30;

    public const GRACE_MINUTES = 10;

    /**
     * @return array{token: string, token_id: int, abilities: array<int, string>, expires_at: ?string}
     */
    public static function rotate(PersonalAccessToken $calling): array
    {
        return DB::transaction(function () use ($calling): array {
            /** @var ?Rotation $done */
            $done = Rotation::query()
                ->where('from_token_id', $calling->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            /** @var ?PersonalAccessToken $previous */
            $previous = $done === null ? null : PersonalAccessToken::query()->find($done->to_token_id);
            if ($done !== null && $previous !== null && ! $previous->isRevoked()) {
                return self::credential($done->plain_token, $previous);
            }

            $owner = $calling->tokenable;
            $abilities = is_array($calling->abilities) ? array_values($calling->abilities) : [];
            $new = $owner->createToken((string) $calling->name, $abilities, now()->addDays(self::LIFETIME_DAYS));

            $grace = now()->addMinutes(self::GRACE_MINUTES);
            $calling->forceFill(['expires_at' => $grace])->save();
            Rotation::query()->create([
                'from_token_id' => $calling->id,
                'to_token_id' => $new->accessToken->id,
                'plain_token' => $new->plainTextToken,
                'expires_at' => $grace,
            ]);

            /** @var PersonalAccessToken $token */
            $token = $new->accessToken;

            return self::credential($new->plainTextToken, $token);
        });
    }

    /**
     * @return array{token: string, token_id: int, abilities: array<int, string>, expires_at: ?string}
     */
    private static function credential(string $plain, PersonalAccessToken $token): array
    {
        return [
            'token' => $plain,
            'token_id' => (int) $token->id,
            'abilities' => is_array($token->abilities) ? array_values($token->abilities) : [],
            'expires_at' => $token->expires_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
