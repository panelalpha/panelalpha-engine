<?php

namespace App\Lib\Deploy\Source;

use App\Lib\Deploy\Template\Template;

/**
 * Git remote URL helpers used at clone time.
 *
 * Private HTTPS remotes use a short-lived GIT_ASKPASS script. The repository
 * URL therefore stays clean in process arguments and in .git/config.
 *
 * No Laravel dependencies — unit-testable.
 */
class GitUrl
{
    public static function isHttpsWithoutCredentials(string $repoUrl): bool
    {
        $parsed = parse_url($repoUrl);

        return is_array($parsed)
            && strtolower((string) ($parsed['scheme'] ?? '')) === 'https'
            && !empty($parsed['host'])
            && !isset($parsed['user'])
            && !isset($parsed['pass']);
    }

    public static function assertSafeForToken(string $repoUrl): void
    {
        if (!self::isHttpsWithoutCredentials($repoUrl)) {
            throw new \InvalidArgumentException(
                'A Git token requires an HTTPS repository URL without embedded credentials.'
            );
        }
    }

    public static function askPassScript(string $token): string
    {
        if (trim($token) === '') {
            throw new \InvalidArgumentException('Git token must not be empty.');
        }

        return rtrim(
            Template::named('script/git-askpass')->render(['encoded_token' => base64_encode($token)]),
            "\n"
        );
    }

    /**
     * A git command that cannot stop and ask a human for anything.
     *
     * Needed on every remote operation, not only the ones carrying a token:
     * the tokenless case is the one that prompts. GIT_TERMINAL_PROMPT only
     * closes the terminal, so an askpass program is pointed at /bin/false
     * rather than left unset.
     *
     * @param list<string> $gitCommand
     * @return list<string>
     */
    public static function withoutPrompts(array $gitCommand): array
    {
        return [
            'env',
            'GIT_TERMINAL_PROMPT=0',
            'GIT_ASKPASS=/bin/false',
            'SSH_ASKPASS=/bin/false',
            ...$gitCommand,
        ];
    }

    /**
     * @param list<string> $gitCommand
     * @return list<string>
     */
    public static function withAskPass(array $gitCommand, string $askPassPath): array
    {
        if ($askPassPath === '' || str_contains($askPassPath, "\0")) {
            throw new \InvalidArgumentException('Invalid GIT_ASKPASS path.');
        }

        return [
            'env',
            'GIT_TERMINAL_PROMPT=0',
            'SSH_ASKPASS=/bin/false',
            'GIT_ASKPASS=' . $askPassPath,
            ...$gitCommand,
        ];
    }

    public static function sanitize(string $repoUrl): string
    {
        $parsed = parse_url($repoUrl);
        if ($parsed === false || empty($parsed['host'])) {
            return $repoUrl;
        }
        if (empty($parsed['user']) && empty($parsed['pass'])) {
            return $repoUrl;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    /**
     * owner/repo of any forge remote: https, ssh:// or scp-style
     * git@host:owner/repo.git. GitLab subgroups keep the last two segments.
     */
    public static function ownerAndRepo(string $repoUrl): ?string
    {
        $url = trim($repoUrl);
        // parse_url() reads scp-style remotes as one opaque path.
        $path = !str_contains($url, '://') && preg_match('#^(?:[^@/]+@)?[^:/]+:(.+)$#', $url, $m) === 1
            ? $m[1]
            : (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $path = (string) preg_replace('/\.git$/i', '', trim($path, '/'));
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return count($segments) >= 2 ? implode('/', array_slice($segments, -2)) : null;
    }
}
