<?php

namespace App\Lib\Hub;

/**
 * HMAC-SHA256 over METHOD, path, timestamp and the body's sha256, one per
 * line. The same scheme the Connect panel protocol uses.
 */
final class HubSigner
{
    public static function canonical(string $method, string $path, string $timestamp, string $body): string
    {
        $path = ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        return implode("\n", [strtoupper($method), $path, $timestamp, hash('sha256', $body)]);
    }

    public static function sign(string $method, string $path, string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', self::canonical($method, $path, $timestamp, $body), $secret);
    }

    /** @return array<string, string> */
    public static function headers(
        string $connectionId,
        string $secret,
        string $method,
        string $path,
        string $body,
        ?int $now = null
    ): array {
        $timestamp = (string) ($now ?? time());

        return [
            'X-Server-Id' => $connectionId,
            'X-Timestamp' => $timestamp,
            'X-Signature' => self::sign($method, $path, $timestamp, $body, $secret),
        ];
    }
}
