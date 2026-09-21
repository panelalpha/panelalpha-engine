<?php

namespace App\Lib\Deploy\Source;

/**
 * Whether this engine can clone a repository URL.
 *
 * The single answer for `git_repo` on POST /projects and `source` on
 * POST /source/inspect, which used to decide separately and disagree.
 * No Laravel dependencies: SourceResolver asks it too, and is not a request.
 *
 * Rejections carry a `suggestion` where the meant value is computable.
 * Suggest, never substitute -- rewriting `git@…` to HTTPS behind a caller's
 * back would send an SSH-only host a clone it cannot serve, failing a stage
 * later with nothing to tie it back to the rewrite.
 */
final class GitRepoInput
{
    public const MAX_LENGTH = 2048;

    /** SSH is absent: the engine holds no keys. {@see sshProblem()}. */
    private const SCHEMES = ['http', 'https'];

    private const SCP_STYLE = '#^[^@\s/]+@[^:\s/]+:[^\s]+$#';

    /**
     * Hosts where a repository is always `owner/repo`. A list rather than a
     * rule, because a self-hosted server serves `https://host/repo.git`.
     *
     * @var list<string>
     */
    private const FORGE_HOSTS = [
        'github.com', 'gitlab.com', 'bitbucket.org', 'codeberg.org', 'gitea.com', 'sr.ht',
    ];

    /**
     * The forge a bare `owner/repo` means when nothing says otherwise. The
     * engine's own `system:example:create` has always assumed the same one.
     */
    public const DEFAULT_SHORTHAND_HOST = 'github.com';

    /**
     * `owner/repo` as it is typed on a command line, spelled out as the URL it
     * means. Only the bare two-segment form: anything carrying a scheme, a host
     * or an SSH shape is already a URL and is left alone.
     *
     * `$host` is the forge to assume -- the `default_git_host` setting, where
     * an operator set one. Null or empty means {@see DEFAULT_SHORTHAND_HOST}.
     */
    public static function expandShorthand(string $raw, ?string $host = null): string
    {
        $value = trim($raw);
        if ($value === '' || self::isSsh($value) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            return $value;
        }

        // A host carries a dot and an owner does not, so `gitea.com/repo` is a
        // URL with its scheme left off -- normalise()'s job -- and only a
        // dotless `owner/repo` is the shorthand.
        return preg_match('#^[^/\s.]+/[^/\s]+$#', $value) === 1
            ? 'https://' . self::shorthandHost($host) . '/' . $value
            : $value;
    }

    /**
     * A configured host as it was typed: `https://gitlab.com/` and
     * `gitlab.com` are the same answer, and an empty one is no answer.
     */
    private static function shorthandHost(?string $host): string
    {
        $host = trim($host ?? '');
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host);
        $host = trim($host, '/');

        return $host === '' ? self::DEFAULT_SHORTHAND_HOST : $host;
    }

    /** Supply the scheme a caller left off `github.com/owner/repo`. */
    public static function normalise(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || self::isSsh($raw) || preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) === 1) {
            return $raw;
        }

        return 'https://' . ltrim($raw, '/');
    }

    public static function isSsh(string $raw): bool
    {
        $raw = trim($raw);

        return stripos($raw, 'ssh://') === 0 || preg_match(self::SCP_STYLE, $raw) === 1;
    }

    /** The HTTPS spelling of an SSH remote, or null if it names no repository. */
    public static function httpsEquivalent(string $raw): ?string
    {
        $parsed = RepoUrl::parse(trim($raw));

        return $parsed === null
            ? null
            : 'https://' . $parsed['host'] . '/' . $parsed['owner'] . '/' . $parsed['repo'] . '.git';
    }

    /**
     * @param bool $forToken a `git_token` came with it, so HTTP is out too
     * @return ?array<string, mixed>
     */
    public static function problem(string $field, string $raw, bool $forToken = false): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > self::MAX_LENGTH) {
            return self::problemOf($field, 'too_long',
                'A repository URL must be at most ' . self::MAX_LENGTH . ' characters.');
        }
        if (self::isSsh($value)) {
            return self::sshProblem($field, $value);
        }

        $url = self::normalise($value);
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return self::problemOf($field, 'malformed',
                "'{$value}' could not be read as a repository URL.");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, self::SCHEMES, true)) {
            return self::problemOf($field, 'unsupported_scheme',
                "The engine clones over HTTPS; '{$scheme}' is not a scheme it can use.");
        }
        if (empty($parts['host'])) {
            return self::problemOf($field, 'no_host', "'{$value}' names no host.");
        }

        // What git_token exists to replace: credentials in the URL reach
        // process arguments and .git/config.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::problemOf($field, 'embedded_credentials',
                'A repository URL must not embed credentials. Pass the token as `git_token` '
                . 'instead, which is injected at clone time and never written to .git/config.',
                GitUrl::sanitize($url));
        }

        $segments = self::pathSegments($parts['path'] ?? '');
        if ($segments === []) {
            return self::problemOf($field, 'incomplete', "'{$value}' names a host but no repository.");
        }
        if (count($segments) < 2 && in_array(strtolower($parts['host']), self::FORGE_HOSTS, true)) {
            return self::problemOf($field, 'incomplete',
                "'{$value}' names an account on {$parts['host']}, not a repository. "
                . 'Add the repository name.');
        }

        // Last, so a caller with a token and a typo hears about the typo.
        if ($forToken && $scheme !== 'https') {
            return self::problemOf($field, 'token_requires_https',
                'A Git token requires an HTTPS repository URL.',
                'https://' . substr($url, strlen($scheme) + 3));
        }

        return null;
    }

    /**
     * Refused rather than attempted: the clone authenticates only through a
     * GIT_ASKPASS token, so SSH would reach git and die on the host key two
     * minutes into a deploy job.
     *
     * @return array<string, mixed>
     */
    private static function sshProblem(string $field, string $value): array
    {
        $message = 'SSH remotes are not supported: the engine clones anonymously or with an '
            . 'HTTPS token (`git_token`), and holds no SSH keys.';
        $https = self::httpsEquivalent($value);

        return self::problemOf(
            $field,
            'ssh_unsupported',
            $https === null ? $message : $message . ' Use ' . $https . ' instead.',
            $https
        );
    }

    /** @return list<string> */
    private static function pathSegments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /** @return array<string, mixed> */
    private static function problemOf(
        string $field,
        string $code,
        string $message,
        ?string $suggestion = null
    ): array {
        return array_filter([
            'field' => $field,
            'code' => $field . '_' . $code,
            'message' => $message,
            'expected' => 'https://<host>/<owner>/<repo>[.git]',
            'suggestion' => $suggestion,
            'examples' => ['https://github.com/owner/repo.git', 'github.com/owner/repo'],
        ], static fn (mixed $v): bool => $v !== null);
    }
}
