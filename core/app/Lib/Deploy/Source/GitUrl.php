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
            && ! empty($parsed['host'])
            && ! isset($parsed['user'])
            && ! isset($parsed['pass']);
    }

    public static function assertSafeForToken(string $repoUrl): void
    {
        if (! self::isHttpsWithoutCredentials($repoUrl)) {
            throw new \InvalidArgumentException(
                'A Git token requires an HTTPS repository URL without embedded credentials.'
            );
        }
    }

    public static function askPassScript(string $token, ?string $host = null): string
    {
        if (trim($token) === '') {
            throw new \InvalidArgumentException('Git token must not be empty.');
        }

        return rtrim(
            Template::named('script/git-askpass')->render([
                'encoded_token' => base64_encode($token),
                'host' => self::restrictedHost($host),
            ]),
            "\n"
        );
    }

    /**
     * The host a token helper must see to answer; '' answers no prompt.
     * A host that cannot be quoted into the helper is refused rather than
     * written into it unescaped.
     */
    private static function restrictedHost(?string $host): string
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return '';
        }
        if (preg_match('/\A[a-z0-9.\-\[\]:]+\z/', $host) !== 1) {
            throw new \InvalidArgumentException('Invalid Git repository host.');
        }

        return $host;
    }

    /**
     * No prompt may be answered: GIT_TERMINAL_PROMPT closes the terminal, and the askpass
     * program points at /bin/false so a configured helper cannot sit there waiting.
     *
     * @param  list<string>  $gitCommand
     * @param  list<string>  $protocols  the transports this command may use; none adds no policy
     * @return list<string>
     */
    public static function withoutPrompts(array $gitCommand, array $protocols = []): array
    {
        return [
            'env',
            ...self::transport($protocols),
            'GIT_TERMINAL_PROMPT=0',
            'GIT_ASKPASS=/bin/false',
            'SSH_ASKPASS=/bin/false',
            ...$gitCommand,
        ];
    }

    /**
     * @param  list<string>  $gitCommand
     * @param  list<string>  $protocols  the transports this command may use; none adds no policy
     * @return list<string>
     */
    public static function withAskPass(array $gitCommand, string $askPassPath, array $protocols = []): array
    {
        if ($askPassPath === '' || str_contains($askPassPath, "\0")) {
            throw new \InvalidArgumentException('Invalid GIT_ASKPASS path.');
        }

        return [
            'env',
            ...self::transport($protocols),
            'GIT_TERMINAL_PROMPT=0',
            'SSH_ASKPASS=/bin/false',
            'GIT_ASKPASS='.$askPassPath,
            ...$gitCommand,
        ];
    }

    /**
     * @param  list<string>  $protocols
     * @return list<string>
     */
    private static function transport(array $protocols): array
    {
        return $protocols === [] ? [] : [GitTransport::assignment($protocols)];
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
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function overHttp11(array $command): array
    {
        return self::afterGit($command, ['-c', 'http.version=HTTP/1.1']);
    }

    /**
     * $command with redirects disabled: a remote that answers 3xx must be
     * refused rather than silently lead the connection to another host.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function withoutRedirects(array $command): array
    {
        return self::afterGit($command, ['-c', 'http.followRedirects=false']);
    }

    /**
     * $command pinned to a checked address (git >= 2.37): the connection uses
     * the address the guard checked rather than a second lookup.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function withResolve(array $command, ?string $pin): array
    {
        return $pin === null || $pin === ''
            ? $command
            : self::afterGit($command, ['-c', 'http.curloptResolve='.$pin]);
    }

    /**
     * The host of a Git remote, https or scp-style, or null when it names none.
     */
    public static function hostOf(string $repoUrl): ?string
    {
        $url = trim($repoUrl);
        if ($url === '') {
            return null;
        }
        if (preg_match('#\A(?:[^@/\s]+@)?([^:/\s]+):(?!//)(?:.*)\z#', $url, $m) === 1) {
            return strtolower(trim($m[1], '[]'));
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower(trim($host, '[]')) : null;
    }

    /**
     * The URL git refused to redirect to, from its error output, or null when
     * the failure was not a redirect. The `/info/refs` suffix the fetch used is
     * stripped, so the caller can offer the repository URL itself.
     */
    public static function redirectedTo(string $output): ?string
    {
        if (stripos($output, 'redirect') === false) {
            return null;
        }
        if (preg_match('/^\s*redirect:\s*(\S+)/mi', $output, $m) === 1) {
            return self::repositoryFromRedirect(trim($m[1]));
        }
        if (preg_match('/redirecting to [\'"<]?([^\s\'">]+)/i', $output, $m) === 1) {
            return self::repositoryFromRedirect(trim($m[1]));
        }

        return null;
    }

    private static function repositoryFromRedirect(string $url): string
    {
        return (string) preg_replace('#/info/refs\?.*\z#', '', $url);
    }

    /**
     * @param  list<string>  $command
     * @param  list<string>  $options
     * @return list<string>
     */
    private static function afterGit(array $command, array $options): array
    {
        $git = array_search('git', $command, true);
        if ($git === false) {
            return $command;
        }
        array_splice($command, $git + 1, 0, $options);

        return $command;
    }

    /**
     * Point git's ssh at one key and one known_hosts file, and nothing else:
     * no agent, no ~/.ssh, no prompt, and an unknown or changed host key fails.
     *
     * @param  list<string>  $command  an `env …` argv from withoutPrompts() or withAskPass()
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

        $ssh = 'ssh -i '.$keyPath.' -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes'
            .' -o StrictHostKeyChecking=yes -o UserKnownHostsFile='.$knownHostsPath
            .' -o GlobalKnownHostsFile=/dev/null';

        return ['env', 'GIT_SSH_COMMAND='.$ssh, ...array_slice($command, 1)];
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
        $host = ($ssh && ! empty($parsed['user']) ? $parsed['user'].'@' : '').$parsed['host'];
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        return $scheme.'://'.$host.$port.$path.$query.$fragment;
    }

    /**
     * owner/repo of any forge remote: https, ssh:// or scp-style
     * git@host:owner/repo.git. GitLab subgroups keep the last two segments.
     */
    public static function ownerAndRepo(string $repoUrl): ?string
    {
        $url = trim($repoUrl);
        // parse_url() reads scp-style remotes as one opaque path.
        $path = ! str_contains($url, '://') && preg_match('#^(?:[^@/]+@)?[^:/]+:(.+)$#', $url, $m) === 1
            ? $m[1]
            : (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $path = (string) preg_replace('/\.git$/i', '', trim($path, '/'));
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return count($segments) >= 2 ? implode('/', array_slice($segments, -2)) : null;
    }
}
