<?php

namespace App\Lib\Deploy\Source;

/**
 * A repository URL as host, owner and repo, whatever its spelling; the owner is a namespace that
 * may hold slashes, so only the last path segment is the repo.
 */
final class RepoUrl
{
    /**
     * Host, owner and repo, or null when the URL names no repository; case as typed ({@see segments()} lowercases).
     * Trailing slashes and `.git` are stripped, and SCP-style `git@host:owner/repo` is read too.
     *
     * @return ?array{host: string, owner: string, repo: string}
     */
    public static function parse(string $gitUrl): ?array
    {
        $gitUrl = rtrim(trim($gitUrl), '/');
        if (str_ends_with($gitUrl, '.git')) {
            $gitUrl = substr($gitUrl, 0, -4);
        }
        $gitUrl = rtrim($gitUrl, '/');

        if (preg_match('/^[^@\s\/]+@([^:\s]+):([^\s]+)$/', $gitUrl, $m)) {
            return self::build($m[1], $m[2]);
        }

        $parts = parse_url($gitUrl);
        if (empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        return self::build($parts['host'], $parts['path']);
    }

    /**
     * Split a host and path into host/owner/repo; GitLab's `/-/` route marker and what follows are dropped.
     *
     * @return ?array{host: string, owner: string, repo: string}
     */
    private static function build(string $host, string $path): ?array
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $s): bool => $s !== ''
        ));

        $marker = array_search('-', $segments, true);
        if ($marker !== false) {
            $segments = array_slice($segments, 0, $marker);
        }

        if (count($segments) < 2) {
            return null;
        }

        $repo = array_pop($segments);

        return ['host' => $host, 'owner' => implode('/', $segments), 'repo' => $repo];
    }

    /**
     * The URL as lowercased path segments, each validated on its own: null if any could climb out of the
     * tree it is joined to. The only place operator input becomes a filesystem path.
     *
     * @return ?list<string> host, then each namespace segment, then repo
     */
    public static function segments(string $gitUrl): ?array
    {
        $parsed = self::parse($gitUrl);
        if ($parsed === null) {
            return null;
        }

        $parts = array_merge(
            [$parsed['host']],
            explode('/', $parsed['owner']),
            [$parsed['repo']]
        );

        $segments = [];
        foreach ($parts as $part) {
            $part = strtolower($part);
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
            if (str_contains($part, '/') || str_contains($part, '\\') || str_contains($part, "\0")) {
                return null;
            }
            $segments[] = $part;
        }

        return $segments;
    }

    /** `<host>/<owner>/<repo>`, the form a source recipe directory is named. */
    public static function slug(string $gitUrl): ?string
    {
        $segments = self::segments($gitUrl);

        return $segments === null ? null : implode('/', $segments);
    }
}
