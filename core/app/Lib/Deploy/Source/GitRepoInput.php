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

    /** SSH only with a project's deploy key. {@see sshProblem()}. */
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

    /** Forges where `git@host:owner/repo.git` is `https://host/owner/repo.git`. */
    private const HTTPS_FORGES = ['github.com', 'gitlab.com', 'bitbucket.org', 'codeberg.org', 'gitea.com'];

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

        return self::isShorthand($value)
            ? 'https://' . self::shorthandHost($host) . '/' . $value
            : $value;
    }

    /**
     * A bare `owner/repo`. A host carries a dot and an owner does not, so
     * `gitea.com/repo` is a URL with its scheme left off -- normalise()'s
     * job -- and only a dotless first segment is the shorthand.
     */
    public static function isShorthand(string $raw): bool
    {
        $value = trim($raw);

        return !self::isSsh($value) && preg_match('#^[^/\s.:@]+/[^/\s]+$#', $value) === 1;
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

    /**
     * Supply the scheme a caller left off `github.com/owner/repo`. A bare
     * `owner/repo` is left alone: `https://owner/repo` would send the probe
     * to a host called `owner` (#83), and problem() suggests the URL instead.
     */
    public static function normalise(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || self::isSsh($raw) || self::isShorthand($raw)
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw) === 1) {
            return $raw;
        }

        return 'https://' . ltrim($raw, '/');
    }

    public static function isSsh(string $raw): bool
    {
        $raw = trim($raw);

        return stripos($raw, 'ssh://') === 0 || preg_match(self::SCP_STYLE, $raw) === 1;
    }

    /**
     * The host of an SSH remote as known_hosts names it -- `[host]:port` off
     * port 22 -- or null when the URL is not an SSH remote with a host.
     */
    public static function sshHost(string $raw): ?string
    {
        $raw = trim($raw);
        if (!self::isSsh($raw)) {
            return null;
        }
        if (stripos($raw, 'ssh://') === 0) {
            $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
            $port = (int) (parse_url($raw, PHP_URL_PORT) ?: 22);
        } else {
            $host = strtolower(explode(':', substr($raw, strpos($raw, '@') + 1), 2)[0]);
            $port = 22;
        }
        if ($host === '') {
            return null;
        }

        return $port === 22 ? $host : "[{$host}]:{$port}";
    }

    /**
     * An SSH remote for a project that holds a deploy key: refused only when
     * its host key is not pinned, since the clone would then fail on it.
     *
     * @param callable(string): bool $pinned
     * @return ?array<string, mixed>
     */
    public static function sshProblemWithKey(string $field, string $raw, callable $pinned): ?array
    {
        $host = self::sshHost($raw);
        if ($host === null) {
            return self::problemOf($field, 'malformed', "'{$raw}' names no host.");
        }
        if ($pinned($host)) {
            return null;
        }

        return [
            'field' => $field,
            'code' => $field . '_ssh_host_not_pinned',
            'message' => "The host key of {$host} is not pinned, so the clone could not verify the server. "
                . 'Pin it with POST /projects/{name}/git/deploy-key and `host`.',
            'expected' => 'an SSH remote on github.com, gitlab.com, bitbucket.org or a host pinned on the deploy key',
            'examples' => ['git@github.com:owner/repo.git', 'ssh://git@git.example.com:2222/owner/repo.git'],
        ];
    }

    /**
     * The HTTPS spelling of an SSH remote on a forge that serves the same path
     * over HTTPS, or null. Any other host -- or a forge on another SSH port --
     * is not guessed: its HTTPS address and port are its own.
     */
    public static function httpsEquivalent(string $raw): ?string
    {
        if (!in_array(self::sshHost($raw), self::HTTPS_FORGES, true)) {
            return null;
        }
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
        // Suggested, not expanded: the CLI assumes GitHub for its operator,
        // but an API caller may mean any forge.
        if (self::isShorthand($value)) {
            $url = self::expandShorthand($value);

            return self::problemOf($field, 'shorthand',
                "'{$value}' is owner/repo shorthand, not a repository URL, and the host is not guessed. "
                . "If it is on GitHub, send {$url}.",
                $url);
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
     * Refused rather than attempted: an SSH remote clones only with a
     * project's deploy key, and there is no project yet to hold one.
     *
     * @return array<string, mixed>
     */
    public static function sshProblem(string $field, string $value): array
    {
        $message = 'An SSH remote clones only with a project\'s deploy key, and a project has none until it '
            . 'exists. Create the project without a repository, create its key with POST '
            . '/projects/{name}/git/deploy-key, add the returned public key to the repository as a deploy key, '
            . 'then connect the SSH remote with POST /projects/{name}/git/connect.';
        $https = self::httpsEquivalent($value);

        return self::problemOf(
            $field,
            'ssh_unsupported',
            $https === null ? $message : $message . ' Or use ' . $https . ', with a token if it is private.',
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
