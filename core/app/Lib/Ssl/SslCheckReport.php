<?php

namespace App\Lib\Ssl;

/** An {@see SslCheck} result as `pae ssl:check` prints it. */
final class SslCheckReport
{
    /**
     * @param  array<string, mixed>  $result
     */
    public static function json(array $result): string
    {
        return (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function lines(array $result): array
    {
        return [
            "{$result['domain']}: {$result['status']}",
            '  signer:  '.self::signer($result['signer']),
            '  expires: '.self::expiry($result),
            '  dns:     '.self::dns($result['dns']),
            '  public:  '.self::probe($result['public']),
            '  engine:  '.self::probe($result['engine']).self::installed($result['engine']),
        ];
    }

    /**
     * @param  ?array{common_name: ?string, organization: ?string, country: ?string}  $signer
     */
    private static function signer(?array $signer): string
    {
        if ($signer === null) {
            return '-';
        }

        $parts = array_filter([$signer['organization'], $signer['common_name'], $signer['country']]);

        return $parts === [] ? 'unknown' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function expiry(array $result): string
    {
        return $result['expires_at'] === null ? '-' : "{$result['expires_at']} ({$result['days_remaining']} days)";
    }

    /**
     * @param  array{addresses: list<string>, expected: list<string>, points_here: bool}  $dns
     */
    private static function dns(array $dns): string
    {
        if ($dns['addresses'] === []) {
            return 'does not resolve';
        }

        $where = $dns['points_here'] ? 'points here' : 'expected '.(implode(', ', $dns['expected']) ?: 'none');

        return implode(', ', $dns['addresses'])." ({$where})";
    }

    /**
     * @param  ?array<string, mixed>  $probe
     */
    private static function probe(?array $probe): string
    {
        if ($probe === null) {
            return 'not checked';
        }

        $certificate = $probe['certificate'] ?? null;
        if (! is_array($certificate)) {
            return "{$probe['address']}: {$probe['error']}";
        }

        $expiry = isset($certificate['expires_at'])
            ? ", expires {$certificate['expires_at']} ({$certificate['days_remaining']} days)"
            : '';

        return "{$probe['address']}: {$certificate['status']}, issuer ".($certificate['issuer'] ?? 'unknown').$expiry;
    }

    /**
     * @param  ?array<string, mixed>  $engine
     */
    private static function installed(?array $engine): string
    {
        return match ($engine['serves_installed'] ?? null) {
            true => ', the installed certificate',
            false => ', NOT the installed certificate (reload the webserver?)',
            default => '',
        };
    }
}
