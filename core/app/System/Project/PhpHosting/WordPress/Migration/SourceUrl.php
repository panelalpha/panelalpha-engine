<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Lib\Deploy\HostingUsername;
use RuntimeException;

/**
 * Derives the hosting project username and WordPress install subdirectory
 * from a source WordPress URL. Path segments are never part of the username.
 */
final class SourceUrl
{
    /**
     * Hosting account name: strip leading www., lowercase, then
     * {@see HostingUsername::normalize()} (dots removed, not hyphens).
     * Null when the host is too short after normalisation.
     */
    public static function projectUsername(string $sourceUrl): ?string
    {
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return HostingUsername::normalize($host);
    }

    /**
     * Relative directory under the document root for the WordPress install.
     * Empty string means the document root itself. Trailing wp-login.php or
     * index.php is stripped (same behaviour as the panel ImportController).
     * The admin URL is what users paste (…/wp-admin); the install lives one
     * level above it.
     */
    public static function installSubdirectory(string $sourceUrl): string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        $path = rtrim($path, '/');
        if (str_ends_with($path, 'wp-login.php') || str_ends_with($path, 'index.php')) {
            $slash = strrpos($path, '/');
            $path = $slash === false ? '' : substr($path, 0, $slash);
        }

        if ($path === '/wp-admin' || str_ends_with($path, '/wp-admin')) {
            $path = substr($path, 0, -strlen('/wp-admin'));
        }

        $relative = trim($path, '/');
        if ($relative === '') {
            return '';
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(
                    'Source WordPress URL path must stay under the document root.'
                );
            }
        }

        return $relative;
    }

    /**
     * Public site URL on the destination: https://{label}[/subdir].
     */
    public static function destinationSiteUrl(string $targetLabel, string $sourceUrl): string
    {
        $url = 'https://'.$targetLabel;
        $subdir = self::installSubdirectory($sourceUrl);
        if ($subdir !== '') {
            $url .= '/'.$subdir;
        }

        return $url;
    }
}
