<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\EnvFile;

/**
 * Public-URL aliases. Applications behind the reverse proxy generate http://
 * links unless told the external origin, and every framework spells the
 * variable differently, so all the usual names are set.
 */
final class PublicUrlEnvironment
{
    /**
     * `ORIGIN` is SvelteKit adapter-node's, which refuses to start on an
     * invalid one.
     *
     * @var list<string>
     */
    private const URL_KEYS = ['URL', 'PUBLIC_URL', 'BASE_URL', 'APP_URL', 'ASSET_URL', 'SITE_URL', 'ENDURAIN_HOST', 'ORIGIN'];

    /**
     * URL keys many apps read as a sub-path prefix instead, where blank means
     * "serve from /": DVinyl registers its routes under BASE_URL and exits on
     * `/https://<domain>`.
     *
     * @var list<string>
     */
    private const PATH_PREFIX_KEYS = ['BASE_URL'];

    /**
     * The same fact spelled as a bare hostname: an Apache `php:*-apache` image
     * ships a `ServerName ${SERVERNAME}` vhost whose default answers 403 over an
     * empty document root. `VIRTUAL_HOST` is excluded because it tells a proxy
     * sidecar where to route, which is a different claim from the app's own name.
     *
     * @var list<string>
     */
    private const HOST_KEYS = ['SERVERNAME', 'SERVER_NAME', 'DEFAULT_DOMAIN'];

    /** @var array<string, string> */
    private const HTTPS_FLAGS = ['HTTPS' => 'on', 'SSL' => 'true', 'FORCE_SSL' => 'true'];

    /**
     * `$httpsFlags` is false for an image the engine did not write: MeTube
     * reads `HTTPS=on` as "terminate TLS here" and dies looking for a
     * certificate. TLS ends at the proxy; the URL keys say https.
     *
     * @return array<string, string>
     */
    public static function for(?string $publicUrl, bool $httpsFlags = true): array
    {
        $url = is_string($publicUrl) ? trim($publicUrl) : '';
        if (preg_match('#^https?://#i', $url) !== 1) {
            return [];
        }

        $env = array_fill_keys(self::URL_KEYS, $url);

        $host = self::hostOf($url);
        if ($host !== null) {
            $env = array_merge($env, array_fill_keys(self::HOST_KEYS, $host));
        }

        return $httpsFlags && self::isHttps($url) ? array_merge($env, self::HTTPS_FLAGS) : $env;
    }

    /**
     * The URL keys a blank value in a template can be filled for; a blank
     * path-prefix key means "root" and stays blank.
     *
     * @return list<string>
     */
    public static function blankFillKeys(): array
    {
        return array_values(array_diff(self::URL_KEYS, self::PATH_PREFIX_KEYS));
    }

    /**
     * The path-prefix keys a project's own env file sets blank or to a path:
     * that app reads the key as a prefix, so the full URL must not be forced on it.
     *
     * @param list<?string> $envFiles contents of `.env` / `.env.example`, null when absent
     * @return list<string>
     */
    public static function pathPrefixKeysIn(array $envFiles): array
    {
        $keys = [];
        foreach ($envFiles as $contents) {
            foreach (is_string($contents) ? EnvFile::parse($contents) : [] as $row) {
                $key = ($row['type'] ?? '') === 'variable' ? (string) ($row['key'] ?? '') : '';
                if (in_array($key, self::PATH_PREFIX_KEYS, true)
                    && preg_match('#^[a-z][a-z0-9+.-]*://#i', trim((string) ($row['value'] ?? ''))) !== 1
                ) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** The host a vhost would match on, without the scheme, port or path. */
    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private static function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }
}
