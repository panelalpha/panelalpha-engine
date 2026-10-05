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

    /**
     * GitHub's edge at times refuses older git's HTTP/2 fingerprint once the
     * refs are listed; the same request over HTTP/1.1 is let through.
     */
    public static function refusedOverHttp2(string $stderr): bool
    {
        return preg_match('/expected flush after ref listing/i', $stderr) === 1;
    }

    /**
     * $command asking for HTTP/1.1: `-c http.version=HTTP/1.1` right after
     * `git`, so it also works behind an `env …` wrapper.
     *
     * @param list<string> $command
     * @return list<string>
     */
    public static function overHttp11(array $command): array
    {
        $git = array_search('git', $command, true);
        if ($git === false) {
            return $command;
        }
        array_splice($command, $git + 1, 0, ['-c', 'http.version=HTTP/1.1']);

        return $command;
    }

    /**
     * Point git's ssh at one key and one known_hosts file, and nothing else:
     * no agent, no ~/.ssh, no prompt, and an unknown or changed host key fails.
     *
     * @param list<string> $command an `env …` argv from withoutPrompts() or withAskPass()
     * @return list<string>
     */
    public static function withSshKey(array $command, string $keyPath, string $knownHostsPath): array
    {
        foreach ([$keyPath, $knownHostsPath] as $path) {
            if ($path === '' || preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1) {
                throw new \InvalidArgumentException('Invalid SSH key path.');
            }
        }
        if (($command[0] ?? null) !== 'env') {
            throw new \InvalidArgumentException('Expected an env argv.');
        }

        $ssh = 'ssh -i ' . $keyPath . ' -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes'
            . ' -o StrictHostKeyChecking=yes -o UserKnownHostsFile=' . $knownHostsPath
            . ' -o GlobalKnownHostsFile=/dev/null';

        return ['env', 'GIT_SSH_COMMAND=' . $ssh, ...array_slice($command, 1)];
    }

    public static function sanitize(string $repoUrl): string
    {
        $parsed = parse_url($repoUrl);
        if ($parsed === false || empty($parsed['host'])) {
            return $repoUrl;
        }
        // An SSH remote's user (git@) is the login, not a credential: only a password goes.
        $ssh = in_array(strtolower((string) ($parsed['scheme'] ?? '')), ['ssh', 'git+ssh', 'ssh+git'], true);
        if (empty($parsed['pass']) && ($ssh || empty($parsed['user']))) {
            return $repoUrl;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = ($ssh && !empty($parsed['user']) ? $parsed['user'] . '@' : '') . $parsed['host'];
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
