<?php

namespace App\Lib\Deploy\Source;

use App\Lib\Http\OutboundUrl;
use App\Lib\Net\EgressRefusedException;

/**
 * Whether a repository host is one core must not open a connection to: the
 * {@see OutboundUrl} guard for the git remotes core contacts itself (create,
 * inspection and its submodules, the vault paste check, an SSH remote's connect
 * and the deploy key's host-key scan). The deploy itself
 * runs in the account's DinD, whose network refuses those ranges anyway.
 */
final class RepositoryAddress
{
    /**
     * The reason a repository URL must not be contacted, or null when it may.
     *
     * @param  string  $field  the field to report the problem under
     * @return ?array<string, mixed>
     */
    public static function problem(string $repoUrl, string $field = 'source'): ?array
    {
        $host = self::host($repoUrl);
        if ($host === null) {
            return null;
        }

        $reason = self::refusalOf($host);
        if ($reason === null) {
            return null;
        }

        return [
            'field' => $field,
            'code' => 'host_refused',
            'message' => $reason,
            'expected' => 'a repository on a public address',
            'examples' => ['https://github.com/owner/repo.git'],
        ];
    }

    /** Why core must not contact $host, or null when it may. */
    private static function refusalOf(string $host): ?string
    {
        try {
            self::checkedAddress($host);
        } catch (EgressRefusedException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The address core may connect to for $host, resolved once so the caller pins
     * the connection to what was checked; $host itself when it is a literal address.
     * OutboundUrl::target() refuses an internal address and a host PHP cannot
     * resolve, which closes numeric IPv4 forms.
     *
     * @throws EgressRefusedException with the reason, when core must not contact $host
     */
    public static function checkedAddress(string $host): string
    {
        try {
            return OutboundUrl::target(self::checkedUrl($host))->address() ?? $host;
        } catch (EgressRefusedException) {
            throw new EgressRefusedException(self::reasonRefusing($host));
        } catch (\InvalidArgumentException) {
            throw new EgressRefusedException(self::unresolved($host));
        }
    }

    private static function reasonRefusing(string $host): string
    {
        $refused = OutboundUrl::refusedAddressOf($host);

        return $refused === null ? self::unresolved($host) : self::message($host, $refused);
    }

    /** A URL carrying the host to the guard; only its host is read. */
    private static function checkedUrl(string $host): string
    {
        return 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).'/';
    }

    private static function unresolved(string $host): string
    {
        return "{$host} does not resolve, so this engine cannot check it and does not fetch from it.";
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
