<?php

namespace App\Lib\Helpers;

/**
 * Reduce a redirect target somebody else wrote to a path on the host serving it.
 *
 * The SSO flow takes its target from `users:sso` in the account's own
 * overrides/app.sh, so it is customer input on its way to a Location header.
 * On the tenant's own domain that is harmless -- they own the domain -- but
 * the same route answers on the engine's origin, where a link that bounces
 * somewhere else is a phishing primitive.
 */
final class SafeRedirect
{
    /**
     * Only the path, query and fragment survive. Anything absolute keeps just
     * its path, and anything unparseable falls back to the app root.
     */
    public static function toPath(?string $redirect): string
    {
        $redirect = trim((string) $redirect);
        if ($redirect === '') {
            return '/';
        }

        // Control characters would also split the Location header.
        if (preg_match('/[\x00-\x1f\x7f]/', $redirect) === 1) {
            return '/';
        }

        $parts = parse_url($redirect);
        if ($parts === false) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        $safe = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $safe .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $safe .= '#' . $parts['fragment'];
        }

        // `//host/path` is a URL to another origin, not a path -- and browsers
        // normalise a backslash to a slash, so `/\host` reads the same way.
        return preg_match('#^[/\\\\]{2}#', $safe) === 1 ? '/' : $safe;
    }
}
