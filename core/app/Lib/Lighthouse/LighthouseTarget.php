<?php

namespace App\Lib\Lighthouse;

use App\Models\Domain;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Where the Lighthouse container may be pointed.
 *
 * Headless Chrome runs on the engine's own network, so any URL it is given is
 * a request from inside it. Only this engine's domains are accepted, and
 * Chrome refuses loopback, private and link-local hosts for whatever the page
 * then redirects to or embeds.
 */
final class LighthouseTarget
{
    /**
     * Host patterns Chrome must not resolve. They apply to IP literals too,
     * and the first matching rule wins, so a pinned domain goes before them.
     */
    public const BLOCKED_HOSTS = [
        'localhost',
        '*.localhost',
        '*.internal',
        '0.*',
        '10.*',
        '127.*',
        '169.254.*',
        '192.168.*',
        '172.16.*', '172.17.*', '172.18.*', '172.19.*',
        '172.20.*', '172.21.*', '172.22.*', '172.23.*',
        '172.24.*', '172.25.*', '172.26.*', '172.27.*',
        '172.28.*', '172.29.*', '172.30.*', '172.31.*',
        // The engine's own services, by the names Docker's DNS answers to
        // on the network the lighthouse container shares with them.
        '*.palocal',
        'shared-hosting-*',
        'panelalpha-*',
        'core', 'core-db', 'mail', 'metrics', 'sites-dns', 'sites-db',
        'sites-phpmyadmin', 'sites-http', 'ftp', 'sftp', 'cache-registry',
        'registry-proxy', 'lighthouse',
    ];

    /** @param Closure(string): bool $isOwnDomain */
    public function __construct(private Closure $isOwnDomain)
    {
    }

    public static function forThisEngine(): self
    {
        return new self(fn (string $host): bool => Domain::domainOrAliasExists($host));
    }

    /**
     * The URL's host, lowercased, if it is http(s) on one of this engine's
     * domains; a validation error otherwise.
     */
    public function hostOf(string $url): string
    {
        // A backslash or userinfo is where PHP's parser and Chrome's disagree
        // about which part is the host.
        if (preg_match('/[\\\\\s\x00-\x1f]/', $url) === 1) {
            throw self::refuse('The url must not contain backslashes, whitespace or control characters.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw self::refuse('The url must be an http or https address.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::refuse('The url must not carry a user name or password.');
        }
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw self::refuse('An IP address is not accepted; use one of the domains hosted on this engine.');
        }
        if (!($this->isOwnDomain)($host)) {
            throw self::refuse("{$host} is not a domain hosted on this engine.");
        }

        return $host;
    }

    /** Whether Chrome is told not to resolve this host (patterns use * like Chrome's). */
    public static function blocks(string $host): bool
    {
        foreach (self::BLOCKED_HOSTS as $pattern) {
            if (fnmatch($pattern, strtolower($host))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chrome's --host-resolver-rules: the pinned domain first, then every
     * blocked pattern, then the engine's own address as a literal.
     */
    public static function resolverRules(?string $pinHost, ?string $engineIp): string
    {
        $rules = [];

        if ($pinHost !== null && $engineIp !== null) {
            $rules[] = "MAP {$pinHost} {$engineIp}";
        }
        foreach (self::BLOCKED_HOSTS as $pattern) {
            $rules[] = "MAP {$pattern} ~NOTFOUND";
        }
        if ($engineIp !== null) {
            $rules[] = "MAP {$engineIp} ~NOTFOUND";
        }

        return implode(', ', $rules);
    }

    /**
     * The address the run ended on when that is not one of this engine's
     * domains -- a redirect the resolver rules did not catch. A hop they did
     * block ends on chrome-error://, which carries nothing, and passes.
     *
     * @param array<string, mixed> $report
     */
    public function strayedTo(array $report): ?string
    {
        foreach (['mainDocumentUrl', 'finalDisplayedUrl', 'finalUrl'] as $key) {
            $url = $report[$key] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }

            $parts = parse_url($url);
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            if ($scheme === 'chrome-error') {
                continue;
            }

            $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
            if (!in_array($scheme, ['http', 'https'], true)
                || $host === ''
                || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
                || !($this->isOwnDomain)($host)
            ) {
                return $url;
            }
        }

        return null;
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['url' => $message]);
    }
}
