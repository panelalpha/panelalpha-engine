<?php

namespace App\Lib\Deploy\Source;

/**
 * A repository URL broken into the things the engine keys anything by:
 * host, owner and repo. The owner is a namespace and may itself hold slashes
 * — a GitLab subgroup makes `gitlab.com/rtraceio/web/flink` owner
 * `rtraceio/web`, repo `flink` — so only the last path segment is the repo.
 *
 * Two trees are addressed this way — the paemd pages under
 * `/opt/panelalpha/shared-hosting/paemd-pages` and the source recipes under
 * `resources/sources/` — and a project reaches them by the URL it was cloned
 * from, in whichever spelling the operator typed. `git@github.com:o/r.git`,
 * `https://GitHub.com/O/R/` and `https://github.com/o/r` are one repository,
 * so they have to resolve to one path.
 *
 * Lives here rather than beside either consumer because "what repository is
 * this URL" is not a question about app configs or about recipes.
 *
 * No Laravel dependencies — unit-testable.
 */
final class RepoUrl
{
    /**
     * Host, owner and repo, or null when the URL names no repository.
     *
     * Case is left as typed; {@see segments()} is what lowercases, because a
     * caller comparing two URLs and a caller building a path want different
     * things from the same parse.
     *
     * @return ?array{host: string, owner: string, repo: string}
     */
    public static function parse(string $gitUrl): ?array
    {
        // Normalise: strip trailing slashes and optional .git suffix
        $gitUrl = rtrim(trim($gitUrl), '/');
        if (str_ends_with($gitUrl, '.git')) {
            $gitUrl = substr($gitUrl, 0, -4);
        }
        $gitUrl = rtrim($gitUrl, '/');

        // SCP-style git URLs: git@host:owner/.../repo
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
     * Split a host and path into host/owner/repo, the repo being the last
     * segment and the owner every namespace segment before it.
     *
     * @return ?array{host: string, owner: string, repo: string}
     */
    private static function build(string $host, string $path): ?array
    {
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $s): bool => $s !== ''
        ));

        // GitLab decorates project URLs with a `/-/` route marker
        // (`group/sub/repo/-/tree/main`); the repository is everything before it.
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
     * The URL as path segments — lowercased, and rejected if any of them
     * could climb out of the tree they are about to be joined to.
     *
     * A repository URL is operator input that becomes a filesystem path, so
     * this is the only place that conversion happens: `..` and separators in
     * an owner or repo name are how a crafted remote would read a YAML file
     * from somewhere else on the host.
     *
     * @return ?list<string> host, then each namespace segment, then repo
     */
    public static function segments(string $gitUrl): ?array
    {
        $parsed = self::parse($gitUrl);
        if ($parsed === null) {
            return null;
        }

        // The owner may be a multi-level namespace, so validate every segment
        // on its own — a subgroup separator is legitimate, `..` never is.
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
