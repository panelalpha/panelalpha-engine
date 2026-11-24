<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\Models\HubSsoLogin;
use Illuminate\Database\QueryException;

/**
 * A Hub admin login ticket: HS256, signed with the connection's Hub secret, for one app, for a minute, once. Every
 * check must hold and none says which one failed: a refusal is just null.
 */
final class HubTicket
{
    public const PURPOSE = 'admin_sso';

    private const HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    private const CLOCK_SKEW_SECONDS = 30;

    private const MIN_JTI_LENGTH = 22;

    private const MAX_LENGTH = 4096;

    /**
     * The ticket's claims once it is accepted and marked used, or null.
     *
     * @return ?array<string, mixed>
     */
    public static function accept(string $ticket, string $app, ?int $now = null): ?array
    {
        $connection = HubConnection::active();
        if ($connection === null || ! $connection->grants(HubGrants::ADMIN_SSO) || strlen($ticket) > self::MAX_LENGTH) {
            return null;
        }
        $claims = self::verified($ticket, $connection->ticketSecrets());
        if ($claims === null || ! self::holds($claims, $connection, $app, $now ?? time())) {
            return null;
        }

        return self::markUsed($claims, $app) ? $claims : null;
    }

    /**
     * @param  list<string>  $secrets
     * @return ?array<string, mixed>
     */
    private static function verified(string $ticket, array $secrets): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 3 || $secrets === []) {
            return null;
        }
        [$header, $payload, $signature] = $parts;
        if (self::json($header) !== self::HEADER) {
            return null;
        }

        $signed = false;
        foreach ($secrets as $secret) {
            $signed = hash_equals(self::encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), $signature) || $signed;
        }

        return $signed ? self::json($payload) : null;
    }

    /** @param  array<string, mixed>  $claims */
    private static function holds(array $claims, HubConnection $connection, string $app, int $now): bool
    {
        return ($claims['iss'] ?? null) === rtrim($connection->hub_url, '/')
            && ($claims['aud'] ?? null) === $connection->connection_id
            && ($claims['purpose'] ?? null) === self::PURPOSE
            && ($claims['app'] ?? null) === $app
            && is_int($claims['exp'] ?? null) && $now <= $claims['exp'] + self::CLOCK_SKEW_SECONDS
            && is_int($claims['iat'] ?? null) && $now >= $claims['iat'] - self::CLOCK_SKEW_SECONDS
            && is_string($claims['jti'] ?? null) && strlen($claims['jti']) >= self::MIN_JTI_LENGTH && strlen($claims['jti']) <= 128;
    }

    /** @param  array<string, mixed>  $claims */
    private static function markUsed(array $claims, string $app): bool
    {
        try {
            HubSsoLogin::query()->create([
                'jti' => $claims['jti'],
                'app' => $app,
                'sub' => is_scalar($claims['sub'] ?? null) ? mb_substr((string) $claims['sub'], 0, 64) : null,
                'at' => now(),
            ]);
        } catch (QueryException) {
            return false;
        }

        return true;
    }

    /** @return ?array<string, mixed> */
    private static function json(string $part): ?array
    {
        $raw = base64_decode(strtr($part, '-_', '+/'), true);
        $decoded = $raw === false ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
