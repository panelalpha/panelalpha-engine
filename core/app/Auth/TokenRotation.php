<?php

namespace App\Auth;

use App\Lib\Hub\HubTokens;
use App\Models\PersonalAccessToken;
use App\Models\TokenRotation as Rotation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Replace a token with a new one of the same name and abilities. The old one
 * keeps working for a grace period, and rotating it again within that period
 * returns the same new token rather than minting a third.
 *
 * The new token lives as long as the calling one was issued for, and never
 * expires when that one did not. A Hub connection token always gets the Hub's
 * own lifetime.
 */
final class TokenRotation
{
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

            $issuedExpiry = $done === null ? $calling->expires_at : $done->from_expires_at;
            $owner = $calling->tokenable;
            $abilities = is_array($calling->abilities) ? array_values($calling->abilities) : [];
            $new = $owner->createToken((string) $calling->name, $abilities, self::expiryFor($calling, $issuedExpiry));

            $grace = $done?->expires_at ?? now()->addMinutes(self::GRACE_MINUTES);
            $calling->forceFill(['expires_at' => $grace])->save();
            Rotation::query()->updateOrCreate(['from_token_id' => $calling->id], [
                'to_token_id' => $new->accessToken->id,
                'plain_token' => $new->plainTextToken,
                'expires_at' => $grace,
                'from_expires_at' => $issuedExpiry,
            ]);

            /** @var PersonalAccessToken $token */
            $token = $new->accessToken;
            $token->forceFill(['hub' => (bool) $calling->hub])->save();

            return self::credential($new->plainTextToken, $token);
        });
    }

    /** A token rotated before has its own expiry cut to the grace already; the one it was issued with is kept on that rotation. */
    private static function expiryFor(PersonalAccessToken $calling, ?CarbonInterface $issuedExpiry): ?CarbonInterface
    {
        if (HubTokens::connectionIdOf($calling) !== null) {
            return now()->addDays(HubTokens::LIFETIME_DAYS);
        }

        if ($issuedExpiry === null) {
            return null;
        }

        return $calling->created_at === null
            ? $issuedExpiry
            : now()->addSeconds($issuedExpiry->getTimestamp() - $calling->created_at->getTimestamp());
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
