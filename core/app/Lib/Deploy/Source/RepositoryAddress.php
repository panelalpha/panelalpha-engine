<?php

namespace App\Lib\Deploy\Source;

use App\Lib\Http\OutboundUrl;

/**
 * Whether a repository host is one core must not open a connection to: the
 * {@see OutboundUrl} guard for the git remotes core contacts itself (create,
 * inspection and its submodules, the vault paste check). The deploy itself
 * runs in the account's DinD, whose network refuses those ranges anyway.
 */
final class RepositoryAddress
{
    /**
     * The reason a repository URL must not be contacted, or null when it may.
     *
     * @param string $field the field to report the problem under
     * @return ?array<string, mixed>
     */
    public static function problem(string $repoUrl, string $field = 'source'): ?array
    {
        $host = self::host($repoUrl);
        if ($host === null) {
            return null;
        }

        $refused = OutboundUrl::refusedAddressOf($host);
        if ($refused === null) {
            return null;
        }

        return [
            'field' => $field,
            'code' => 'host_refused',
            'message' => self::message($host, $refused),
            'expected' => 'a repository on a public address',
            'examples' => ['https://github.com/owner/repo.git'],
        ];
    }

    /** Whether a repository URL core would contact resolves to a refused address. */
    public static function refused(string $repoUrl): bool
    {
        return self::problem($repoUrl) !== null;
    }

    /** The host of a git remote, or null when it is not a remote with a host. */
    public static function host(string $repoUrl): ?string
    {
        $raw = trim($repoUrl);
        if ($raw === '') {
            return null;
        }

        if (GitRepoInput::isSsh($raw)) {
            return self::sshHost($raw);
        }

        $host = parse_url(GitRepoInput::normalise($raw), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? self::unbracket($host) : null;
    }

    /**
     * GitRepoInput::sshHost() gives `[host]:port` off port 22; only the host is kept.
     */
    private static function sshHost(string $raw): ?string
    {
        $ssh = GitRepoInput::sshHost($raw);

        return $ssh === null ? null : self::unbracket(preg_replace('/^\[([^\]]+)\].*$/', '$1', $ssh) ?? $ssh);
    }

    /**
     * The address is the one place the problem is reported; a `host` that is
     * itself the address is named, not said to resolve to itself.
     */
    private static function message(string $host, string $address): string
    {
        $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;

        return $isLiteral
            ? "{$address} is an address this engine does not fetch from."
            : "{$host} resolves to {$address}, which this engine does not fetch from.";
    }

    private static function unbracket(string $host): string
    {
        return trim($host, '[]');
    }
}
