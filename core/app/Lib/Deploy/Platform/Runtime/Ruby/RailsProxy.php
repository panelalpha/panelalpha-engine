<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Template\Template;

/**
 * What a Rails app has to be told about the TLS-terminating proxy: force_ssl off, since its HSTS
 * pins browsers to an often self-signed certificate, and assume_ssl on for an https public URL.
 */
final class RailsProxy
{
    private const HTTPS = 'https://';

    /**
     * @return array<string, string>
     */
    public static function sslEnvironment(?string $publicUrl): array
    {
        return [
            'RAILS_FORCE_SSL' => 'false',
            'RAILS_ASSUME_SSL' => self::isHttps($publicUrl) ? 'true' : 'false',
        ];
    }

    /**
     * Adds the proxy hostname to Host Authorization only when the app already keeps a whitelist:
     * an empty `config.hosts` accepts any Host, and appending would 403 every other name.
     */
    public static function hostAuthorizationInitializer(?string $publicUrl = null): string
    {
        return Template::named('rails/host-authorization')->render([
            'host' => self::rubyHost($publicUrl),
        ]);
    }

    private static function isHttps(?string $publicUrl): bool
    {
        return is_string($publicUrl) && str_starts_with(strtolower(trim($publicUrl)), self::HTTPS);
    }

    private static function rubyHost(?string $publicUrl): string
    {
        $host = is_string($publicUrl) ? parse_url($publicUrl, PHP_URL_HOST) : null;
        $host = is_string($host) ? trim($host) : '';

        return $host === '' ? '' : var_export($host, true);
    }
}
