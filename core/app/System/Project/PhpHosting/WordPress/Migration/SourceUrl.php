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
     * Hosting account name: strip leading www., lowercase, then {@see HostingUsername::normalize()} (dots removed,
     * not hyphens). Null when the host is too short after normalisation.
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
     * Directory under the document root for the install, '' for the root itself. A trailing wp-login.php or index.php
     * goes, as in the panel ImportController, and so does the wp-admin users paste: the install lives one level above.
     */
    public static function installSubdirectory(string $sourceUrl): string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        $relative = trim(self::installPath($path), '/');
        if ($relative === '') {
            return '';
        }

        self::assertUnderDocumentRoot($relative);

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

    private static function installPath(string $path): string
    {
        $path = rtrim($path, '/');
        if (str_ends_with($path, 'wp-login.php') || str_ends_with($path, 'index.php')) {
            $slash = strrpos($path, '/');
            $path = $slash === false ? '' : substr($path, 0, $slash);
        }

        if ($path === '/wp-admin' || str_ends_with($path, '/wp-admin')) {
            $path = substr($path, 0, -strlen('/wp-admin'));
        }

        return $path;
    }

    private static function assertUnderDocumentRoot(string $relative): void
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(
                    'Source WordPress URL path must stay under the document root.'
                );
            }
        }
    }
}
