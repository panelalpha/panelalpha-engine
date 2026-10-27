<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * The source WordPress as it was found: its own home URL, the login page that
 * worked (null when none was needed) and how its REST API is addressed.
 */
final class SourceSite
{
    public const REST_WP_JSON = 'wp_json';

    public const REST_QUERY = 'rest_route';

    public const REST_MODES = [self::REST_WP_JSON, self::REST_QUERY];

    public readonly string $url;

    public function __construct(
        string $url,
        public readonly ?string $loginUrl = null,
        public readonly string $restMode = self::REST_WP_JSON,
    ) {
        $this->url = rtrim($url, '/');
    }

    /**
     * REST URL of a route under a site, e.g. `/wp-migrator/v1/info`.
     *
     * @param  array<string, string>  $query
     */
    public static function restUrlFor(string $base, string $mode, string $route, array $query = []): string
    {
        $base = rtrim($base, '/');
        $extra = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($mode === self::REST_QUERY) {
            return $base.'?rest_route='.$route.($extra === '' ? '' : '&'.$extra);
        }

        return $base.'/wp-json'.$route.($extra === '' ? '' : '?'.$extra);
    }
}
