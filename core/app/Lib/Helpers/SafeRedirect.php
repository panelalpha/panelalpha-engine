<?php

namespace App\Lib\Helpers;

/**
 * Reduce a redirect target somebody else wrote (the SSO flow's) to a path on the host serving it,
 * since the route also answers on the engine's origin.
 */
final class SafeRedirect
{
    /**
     * Only the path, query and fragment survive; control characters, an unparseable target and
     * `//host` or `/\host` fall back to the app root.
     */
    public static function toPath(?string $redirect): string
    {
        $redirect = trim((string) $redirect);
        if ($redirect === '') {
            return '/';
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $redirect) === 1) {
            return '/';
        }

        $parts = parse_url($redirect);
        if ($parts === false) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.ltrim($path, '/');
        }

        $safe = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $safe .= '?'.$parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $safe .= '#'.$parts['fragment'];
        }

        return preg_match('#^[/\\\\]{2}#', $safe) === 1 ? '/' : $safe;
    }
}
