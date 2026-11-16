<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployLogger;

/**
 * Strips what identifies a customer, an account or a secret from text leaving the server: stricter than
 * {@see DeployLogger::sanitize()}, which writes for their own panel. Over-masking is free, so ambiguity masks.
 */
class Redactor
{
    public const MASK = '***';

    public const ACCOUNT_PLACEHOLDER = '<account>';

    public const ADDRESS_PLACEHOLDER = '<ip>';

    /** Longest single line kept, in bytes. */
    public const MAX_LINE_BYTES = 500;

    /** Most lines kept in a tail. */
    public const MAX_LINES = 80;

    /** Hard ceiling on a whole tail, in bytes. */
    public const MAX_TAIL_BYTES = 16384;

    /** Hard ceiling on one block of hand-written prose, in bytes. */
    public const MAX_PROSE_BYTES = 8000;

    /** Entries kept from any one list or map inside a structured report. */
    public const MAX_TREE_ITEMS = 100;

    /** How far into a structured report the scrub descends. */
    public const MAX_TREE_DEPTH = 8;

    /**
     * Assignment keys whose value is always masked, matched greedily on any identifier containing a word,
     * so APP_KEY, DB_PASSWORD and MY_CUSTOM_TOKEN_2 are covered without enumerating them.
     */
    private const SECRET_KEY_WORDS =
        'TOKEN|SECRET|PASSWORD|PASSWD|PWD|APIKEY|KEY|DSN|CREDENTIAL|AUTH|SALT|SIGNATURE|PRIVATE|SESSION|COOKIE|LICENSE';

    /**
     * Vendor token shapes, matched on their prefix so a bare pasted token is caught: GitHub classic and
     * fine-grained, GitLab, npm, an AWS access key id, Slack, Stripe, OpenAI-style and a JWT, in that order.
     *
     * @var list<string>
     */
    private const TOKEN_PATTERNS = [
        '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',
        '/\bglpat-[A-Za-z0-9\-_]{16,}\b/',
        '/\bnpm_[A-Za-z0-9]{20,}\b/',
        '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',
        '/\bsk-[A-Za-z0-9]{32,}\b/',
        '/\bey[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
    ];

    /**
     * An IPv4 address spelled inside a hostname: dashed anywhere, as in `10-0-0-5.panelalpha.direct`, or dotted in front
     * of a further label, as in `app.10.0.0.5.nip.io`. Neither is part of a longer run of numbers.
     *
     * @var list<string>
     */
    private const ADDRESS_NAME_PATTERNS = [
        '/(?<!\d)(?<!\d-)(\d{1,3})-(\d{1,3})-(\d{1,3})-(\d{1,3})(?!\d)(?!-\d)/',
        '/(?<!\d)(?<!\d\.)(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?=\.[a-z])/i',
    ];

    /**
     * Lines dropped whole rather than masked in place, because the interesting part of them is the secret.
     *
     * @var list<string>
     */
    private const DROP_LINE_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/-----BEGIN OPENSSH PRIVATE KEY-----/',
        '/-----BEGIN CERTIFICATE-----/',
    ];

    /**
     * Redact one line, '' when it must be dropped; the repository goes before the account name, often part of it.
     * A null $maxBytes turns truncation off for {@see prose()}, where a typed paragraph is one long line.
     */
    public static function line(
        string $line,
        ?string $username = null,
        ?int $maxBytes = self::MAX_LINE_BYTES,
        ?PrivateRepoMask $repo = null
    ): string {
        $line = self::stripAnsi($line);
        $line = $repo?->apply($line) ?? $line;

        foreach (self::DROP_LINE_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return '';
            }
        }

        $line = self::maskUrlCredentials($line);
        $line = self::maskAuthorizationHeaders($line);
        $line = self::maskSecretAssignments($line);
        $line = self::maskVendorTokens($line);
        $line = self::maskHighEntropyRuns($line);
        $line = self::maskEmails($line);
        $line = self::maskAddressNames($line);
        $line = self::maskPublicIps($line);
        $line = self::maskHomePaths($line, $username);

        $line = trim($line);
        if ($line === '') {
            return '';
        }

        if ($maxBytes !== null && strlen($line) > $maxBytes) {
            $line = mb_strcut($line, 0, $maxBytes).'…';
        }

        return $line;
    }

    /**
     * Redact prose a person wrote, such as a bug report's description: masked like {@see text()}, but capped only as
     * a block, with blank lines kept and collapsed to one, so a dropped private key leaves no hole either.
     */
    public static function prose(
        string $text,
        ?string $username = null,
        int $maxBytes = self::MAX_PROSE_BYTES,
        ?PrivateRepoMask $repo = null
    ): string {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [self::MASK] as $line) {
            $redacted = self::line($line, $username, null, $repo);
            if ($redacted === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }
            $lines[] = $redacted;
        }

        $text = trim(implode("\n", $lines));

        return strlen($text) > $maxBytes ? mb_strcut($text, 0, $maxBytes).'…' : $text;
    }

    /**
     * Redact a block of log lines, keeping the most recent ones.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function tail(array $lines, ?string $username = null, ?PrivateRepoMask $repo = null): array
    {
        $lines = array_slice($lines, -self::MAX_LINES);

        $kept = [];
        foreach ($lines as $line) {
            $redacted = self::line($line, $username, repo: $repo);
            if ($redacted !== '') {
                $kept[] = $redacted;
            }
        }

        return self::newestThatFit($kept);
    }

    /**
     * Trimmed from the front until the whole tail fits: the end of a failing build says why it failed.
     *
     * @param  list<string>  $kept
     * @return list<string>
     */
    private static function newestThatFit(array $kept): array
    {
        $bytes = 0;
        $result = [];
        foreach (array_reverse($kept) as $line) {
            $bytes += strlen($line) + 1;
            if ($bytes > self::MAX_TAIL_BYTES) {
                break;
            }
            $result[] = $line;
        }

        return array_reverse($result);
    }

    /**
     * Redact a single free-text value (an error message, a signature).
     */
    public static function text(string $text, ?string $username = null, ?PrivateRepoMask $repo = null): string
    {
        $parts = [];
        foreach (preg_split('/\R/', $text) ?: [self::MASK] as $line) {
            $redacted = self::line($line, $username, repo: $repo);
            if ($redacted !== '') {
                $parts[] = $redacted;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Redact a structured report by shape, not an allow-list: strings go through {@see line()}, a key naming a secret
     * masks its value whole, lists and depth are capped. Scalars and null pass through; anything else is dropped.
     */
    public static function tree(
        mixed $value,
        ?string $username = null,
        int $maxItems = self::MAX_TREE_ITEMS,
        int $depth = self::MAX_TREE_DEPTH
    ): mixed {
        if (is_string($value)) {
            return self::line($value, $username);
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }

        if ($depth <= 0) {
            return '…';
        }

        $scrubbed = [];
        $kept = 0;
        foreach ($value as $key => $item) {
            if ($kept >= $maxItems) {
                $scrubbed['…'] = 'truncated';
                break;
            }
            $kept++;
            $scrubbed[$key] = self::namesASecret((string) $key)
                ? self::maskWholly($item)
                : self::tree($item, $username, $maxItems, $depth - 1);
        }

        return $scrubbed;
    }

    /**
     * Does this key name something whose value is a secret whatever it looks like? The same greedy word list
     * as the assignment masking, so a key is covered here exactly when the word would be inline.
     */
    private static function namesASecret(string $key): bool
    {
        return preg_match('/(?:'.self::SECRET_KEY_WORDS.')/i', $key) === 1;
    }

    /**
     * Replace a value the key already condemned, keeping a list's shape. Booleans and nulls stay: a secret is a
     * string, and masking npm's `"private": true` would only lose the signal.
     */
    private static function maskWholly(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::maskWholly($item), $value);
        }

        return is_bool($value) || $value === null ? $value : self::MASK;
    }

    /** A pattern PCRE gives up on, at its backtrack limit, masks the whole value instead of emptying it. */
    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? self::MASK;
    }

    private static function stripAnsi(string $line): string
    {
        return self::replace('/\x1B(?:\[[0-9;?]*[A-Za-z]|\][^\x07]*\x07)/', '', $line);
    }

    private static function maskUrlCredentials(string $line): string
    {
        return self::replace(
            '#([a-z][a-z0-9+.-]*://)[^\s/:@]*(:[^\s/@]*)?@#i',
            '$1'.self::MASK.'@',
            $line
        );
    }

    private static function maskAuthorizationHeaders(string $line): string
    {
        return self::replace(
            '/((?:Authorization|Proxy-Authorization)\s*:\s*(?:Bearer|Basic|Token))\s+\S+/i',
            '$1 '.self::MASK,
            $line
        );
    }

    /**
     * KEY=value, KEY: value, --key=value and "key": "value", tried only where an assigned identifier starts: one pass.
     * A bare `Authorization:` is left to maskAuthorizationHeaders(), or the scheme would be masked and the token kept.
     */
    private static function maskSecretAssignments(string $line): string
    {
        $id = '[A-Za-z0-9_.\-]';
        $assigned = '(?='.$id.'++["\']?\s*[:=])';
        $secret = '(?='.$id.'*?(?:'.self::SECRET_KEY_WORDS.'))';
        $key = '(?<!'.$id.')(?!Authorization\s*:)'.$assigned.$secret.'('.$id.'++)';

        return self::replace('/'.$key.'(["\']?\s*[:=]\s*["\']?)([^\s"\',;]+)/i', '$1$2'.self::MASK, $line);
    }

    private static function maskVendorTokens(string $line): string
    {
        foreach (self::TOKEN_PATTERNS as $pattern) {
            $line = self::replace($pattern, self::MASK, $line);
        }

        return $line;
    }

    /**
     * Long unbroken hex or base64 runs: layer digests are noise and the same shape is a leaked token, so both go.
     * 40 chars is above any English word or version string.
     */
    private static function maskHighEntropyRuns(string $line): string
    {
        $line = self::replace('/\bsha(?:256|512):[A-Fa-f0-9]{16,}\b/', '<digest>', $line);
        $line = self::replace('/\b[A-Fa-f0-9]{40,}\b/', '<hex>', $line);
        $line = self::replace('#\b[A-Za-z0-9+/]{40,}={0,2}\b#', '<b64>', $line);

        return $line;
    }

    private static function maskEmails(string $line): string
    {
        return self::replace(
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            '<email>',
            $line
        );
    }

    /**
     * An address a hostname spells is the server's, such as the one in every name under its `.panelalpha.direct` zone,
     * so private ranges go too: no bind address is written that way.
     */
    private static function maskAddressNames(string $line): string
    {
        foreach (self::ADDRESS_NAME_PATTERNS as $pattern) {
            $line = preg_replace_callback(
                $pattern,
                static fn (array $m): string => self::hasOctetsOnly($m) ? self::ADDRESS_PLACEHOLDER : $m[0],
                $line
            ) ?? self::MASK;
        }

        return $line;
    }

    /**
     * Public IP literals identify the box the report came from. Loopback and RFC1918 addresses stay:
     * "listening on 0.0.0.0:3000" is exactly the kind of line a port-detection bug needs.
     */
    private static function maskPublicIps(string $line): string
    {
        $line = self::maskPublicIpv4($line);

        return self::maskIpv6($line);
    }

    private static function maskPublicIpv4(string $line): string
    {
        return preg_replace_callback(
            '/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/',
            static fn (array $m): string => self::hasOctetsOnly($m) && ! self::isPrivateIpv4($m[0])
                ? self::ADDRESS_PLACEHOLDER
                : $m[0],
            $line
        ) ?? self::MASK;
    }

    /**
     * A quad with a part over 255 is not an address but a version string or a size.
     *
     * @param  array<int, string>  $match  the whole match, then its four parts
     */
    private static function hasOctetsOnly(array $match): bool
    {
        foreach (array_slice($match, 1) as $octet) {
            if ((int) $octet > 255) {
                return false;
            }
        }

        return true;
    }

    /** Anything with three or more colon-separated hex groups. */
    private static function maskIpv6(string $line): string
    {
        return self::replace(
            '/\b(?:[A-Fa-f0-9]{1,4}:){3,7}[A-Fa-f0-9]{1,4}\b/',
            '<ip6>',
            $line
        );
    }

    public static function isPrivateIpv4(string $ip): bool
    {
        $parts = array_map('intval', explode('.', $ip));
        if (count($parts) !== 4) {
            return false;
        }
        [$a, $b] = $parts;

        return $a === 10
            || $a === 127
            || $a === 0
            || ($a === 192 && $b === 168)
            || ($a === 172 && $b >= 16 && $b <= 31)
            || ($a === 169 && $b === 254);
    }

    /**
     * The account's own name and home directory, on nearly every line of a build log, and any other
     * account's home that leaks into a message: all identify a customer.
     */
    private static function maskHomePaths(string $line, ?string $username): string
    {
        if ($username !== null && $username !== '') {
            $quoted = preg_quote($username, '#');
            $line = self::replace('#/home/'.$quoted.'\b#', '/home/'.self::ACCOUNT_PLACEHOLDER, $line);
            $line = self::replace('/\b'.$quoted.'\b/', self::ACCOUNT_PLACEHOLDER, $line);
        }

        return self::replace(
            '#/home/[A-Za-z0-9_.\-]+#',
            '/home/'.self::ACCOUNT_PLACEHOLDER,
            $line
        );
    }
}
