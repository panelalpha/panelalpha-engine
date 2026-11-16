<?php

namespace App\Lib\Ssl;

use App\Models\User;

/**
 * The certificate a main-domain rename leaves on the name it moves away from, for the panel's primary-domain switch,
 * which adds that name back as an addon moments later. Only inside that window does the name find it again; added any
 * later, the name's leftover material is discarded and it gets a certificate of its own.
 */
final class KeptCertificate
{
    public const DETAIL = 'kept_certificate';

    public const SECONDS = 600;

    public static function keep(User $user, string $domain): void
    {
        $user->setDetails([self::DETAIL => [
            'domain' => strtolower($domain),
            'until' => now()->addSeconds(self::SECONDS)->getTimestamp(),
        ]]);
    }

    public static function isKeptFor(User $user, string $domain): bool
    {
        $kept = $user->getDetails()[self::DETAIL] ?? null;

        return is_array($kept)
            && strcasecmp((string) ($kept['domain'] ?? ''), $domain) === 0
            && (int) ($kept['until'] ?? 0) >= now()->getTimestamp();
    }
}
