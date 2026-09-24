<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * Strip anything that identifies a customer, an account, or a secret from
 * text that is about to leave the customer's server.
 *
 * This is a *stricter* pass than {@see \App\Lib\Deploy\DeployLog\DeployLogger::sanitize()},
 * and the difference is the whole point. That one is written for "the customer
 * may read this in their own panel", where their own repository path and their
 * own home directory are not secrets. This one is written for "this is leaving
 * the machine", where they are.
 *
 * The rule the whole class follows: over-masking is free, under-masking is not.
 * When a pattern is ambiguous it masks.
 *
 * No Laravel dependencies — unit-testable.
 */
class Redactor
{
    public const MASK = '***';
    public const ACCOUNT_PLACEHOLDER = '<account>';

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
     * Assignment keys whose value is always masked. Deliberately greedy: it
     * matches any identifier *containing* one of these words, so APP_KEY,
     * DB_PASSWORD, NEXT_PUBLIC_STRIPE_SECRET_KEY and MY_CUSTOM_TOKEN_2 are
     * all covered without enumerating them.
     */
    private const SECRET_KEY_WORDS =
        'TOKEN|SECRET|PASSWORD|PASSWD|PWD|APIKEY|KEY|DSN|CREDENTIAL|AUTH|SALT|SIGNATURE|PRIVATE|SESSION|COOKIE|LICENSE';

    /**
     * Vendor token shapes. Matched on their own prefix so a token pasted into
     * a log without an assignment around it is still caught.
     *
     * @var list<string>
     */
    private const TOKEN_PATTERNS = [
        '/\bgh[pousr]_[A-Za-z0-9]{16,}\b/',                 // GitHub classic
        '/\bgithub_pat_[A-Za-z0-9_]{20,}\b/',               // GitHub fine-grained
        '/\bglpat-[A-Za-z0-9\-_]{16,}\b/',                  // GitLab
        '/\bnpm_[A-Za-z0-9]{20,}\b/',                       // npm
        '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',                  // AWS access key id
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',               // Slack
        '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{16,}\b/',       // Stripe
        '/\bsk-[A-Za-z0-9]{32,}\b/',                        // OpenAI-style
        '/\bey[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', // JWT
    ];

    /**
     * Lines that are dropped whole rather than masked in place, because the
     * interesting part of them is the secret.
     *
     * @var list<string>
     */
    private const DROP_LINE_PATTERNS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/-----BEGIN OPENSSH PRIVATE KEY-----/',
        '/-----BEGIN CERTIFICATE-----/',
    ];

    /**
     * Redact one line. Returns '' when the line must be dropped entirely.
     *
     * $maxBytes is the per-line truncation budget; null switches it off, which
     * is what {@see prose()} needs. Build output is a stream of short lines and
     * capping each one is right there; a paragraph somebody typed is one long
     * line, and cutting it at 500 bytes would lose the half that says what
     * went wrong.
     */
    public static function line(
        string $line,
        ?string $username = null,
        ?int $maxBytes = self::MAX_LINE_BYTES,
        ?PrivateRepoMask $repo = null
    ): string {
        $line = self::stripAnsi($line);
        // Before the account name, which is often part of the repository's.
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
        $line = self::maskPublicIps($line);
        $line = self::maskHomePaths($line, $username);

        $line = trim($line);
        if ($line === '') {
            return '';
        }

        if ($maxBytes !== null && strlen($line) > $maxBytes) {
            $line = mb_strcut($line, 0, $maxBytes) . '…';
        }

        return $line;
    }

    /**
     * Redact prose a person wrote on purpose — a bug report's description.
     *
     * The masking is the same as everywhere else: this is still leaving the
     * customer's server, and somebody pasting a build log into a bug report
     * pastes their registry token with it. Two things differ from
     * {@see text()}, and both are about the text being deliberate rather than
     * captured:
     *
     *  - lines are not truncated individually, only the block is. A report
     *    written as one paragraph is one line, and the 500-byte log budget
     *    would amputate it.
     *  - blank lines survive, collapsed to one. Paragraph breaks are the
     *    author's, and a report that arrives as a single wall of text is
     *    harder to read than the one they wrote.
     */
    public static function prose(
        string $text,
        ?string $username = null,
        int $maxBytes = self::MAX_PROSE_BYTES,
        ?PrivateRepoMask $repo = null
    ): string {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $redacted = self::line($line, $username, null, $repo);
            // One blank line, never two: a dropped private key must not leave
            // a hole where the reader assumes something was said.
            if ($redacted === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }
            $lines[] = $redacted;
        }

        $text = trim(implode("\n", $lines));

        return strlen($text) > $maxBytes ? mb_strcut($text, 0, $maxBytes) . '…' : $text;
    }

    /**
     * Redact a block of log lines, keeping the most recent ones.
     *
     * @param list<string> $lines
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

        // Trim from the front until the whole tail fits: the end of a failing
        // build is the part that says why it failed.
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
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $redacted = self::line($line, $username, repo: $repo);
            if ($redacted !== '') {
                $parts[] = $redacted;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Redact a whole structured report — an inspection, a health check.
     *
     * These are assembled for the panel, where the reader owns the machine and
     * their own paths and package names are not secrets. Sending one off the
     * box is a different question, and hand-listing the safe keys of a report
     * that grows every release is how a leak eventually ships: somebody adds a
     * field, nobody adds it to the allow-list, and the redactor silently keeps
     * passing it through because it never knew about it.
     *
     * So this scrubs by shape instead. Every string goes through {@see line()},
     * so tokens, credentials, emails, public IPs and home paths are masked
     * wherever they turn up; every value under a key that *names* a secret is
     * replaced outright, because a value can look innocuous and still be one
     * ("password": "changeme"); and lists, maps and depth are capped, because a
     * report is evidence and not an export.
     *
     * Numbers and booleans pass through untouched. A port, a status code and a
     * duration are the diagnostic, and there is nothing in an integer to leak.
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

        if (!is_array($value)) {
            // int, float, bool, null. Objects do not appear in these reports;
            // anything else is dropped rather than guessed at.
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
     * Does this key name something whose value is a secret whatever it looks
     * like? Same greedy word list the assignment masking uses, so a key is
     * covered here exactly when the same word would be covered inline.
     */
    private static function namesASecret(string $key): bool
    {
        return preg_match('/(?:' . self::SECRET_KEY_WORDS . ')/i', $key) === 1;
    }

    /**
     * Replace a value the key already condemned.
     *
     * A list of secrets is still a list — the shape is worth keeping, so a
     * reader can see there were three of them — but not one element survives.
     *
     * Booleans and nulls are the exception, and they are not a loophole: a
     * secret is a string, and nothing is protected by turning `false` into
     * `***`. What it does instead is corrupt real signal, because plenty of
     * innocent flags are named for words on that list — npm's `"private":
     * true` is the one that turned up first, and reporting it as `"***"` both
     * looks like a leak was caught and loses the fact that the package is
     * private.
     */
    private static function maskWholly(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::maskWholly($item), $value);
        }

        return is_bool($value) || $value === null ? $value : self::MASK;
    }

    private static function stripAnsi(string $line): string
    {
        return (string) preg_replace('/\x1B(?:\[[0-9;?]*[A-Za-z]|\][^\x07]*\x07)/', '', $line);
    }

    private static function maskUrlCredentials(string $line): string
    {
        return (string) preg_replace(
            '#([a-z][a-z0-9+.-]*://)[^\s/:@]*(:[^\s/@]*)?@#i',
            '$1' . self::MASK . '@',
            $line
        );
    }

    private static function maskAuthorizationHeaders(string $line): string
    {
        return (string) preg_replace(
            '/((?:Authorization|Proxy-Authorization)\s*:\s*(?:Bearer|Basic|Token))\s+\S+/i',
            '$1 ' . self::MASK,
            $line
        );
    }

    private static function maskSecretAssignments(string $line): string
    {
        $words = self::SECRET_KEY_WORDS;

        // KEY=value / KEY: value / --key=value / "key": "value"
        //
        // The lookahead keeps this rule off `Authorization: Bearer <token>`,
        // which contains AUTH and would otherwise be read as an assignment
        // whose value is the word "Bearer" — masking the scheme and leaving the
        // token in the clear. maskAuthorizationHeaders() owns that shape.
        return (string) preg_replace(
            '/(?!(?:Proxy-)?Authorization\s*:)([A-Za-z0-9_.\-]*(?:' . $words . ')[A-Za-z0-9_.\-]*)(["\']?\s*[:=]\s*["\']?)([^\s"\',;]+)/i',
            '$1$2' . self::MASK,
            $line
        );
    }

    private static function maskVendorTokens(string $line): string
    {
        foreach (self::TOKEN_PATTERNS as $pattern) {
            $line = (string) preg_replace($pattern, self::MASK, $line);
        }

        return $line;
    }

    /**
     * Long unbroken hex or base64 runs. A build log is full of layer digests,
     * which are noise; the same shape is also what a leaked token looks like.
     * Both are better off masked. 40 chars is above anything a normal English
     * word or a version string reaches.
     */
    private static function maskHighEntropyRuns(string $line): string
    {
        $line = (string) preg_replace('/\bsha(?:256|512):[A-Fa-f0-9]{16,}\b/', '<digest>', $line);
        $line = (string) preg_replace('/\b[A-Fa-f0-9]{40,}\b/', '<hex>', $line);
        $line = (string) preg_replace('#\b[A-Za-z0-9+/]{40,}={0,2}\b#', '<b64>', $line);

        return $line;
    }

    private static function maskEmails(string $line): string
    {
        return (string) preg_replace(
            '/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/',
            '<email>',
            $line
        );
    }

    /**
     * Public IP literals identify the box the report came from. Loopback and
     * RFC1918 addresses stay: "listening on 0.0.0.0:3000" is exactly the kind
     * of line a port-detection bug needs.
     */
    private static function maskPublicIps(string $line): string
    {
        $line = (string) preg_replace_callback(
            '/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/',
            static function (array $m): string {
                foreach (array_slice($m, 1) as $octet) {
                    if ((int) $octet > 255) {
                        return $m[0]; // not an address (a version string, a size)
                    }
                }

                return self::isPrivateIpv4($m[0]) ? $m[0] : '<ip>';
            },
            $line
        );

        // IPv6 literals: anything with 3+ colon-separated hex groups.
        return (string) preg_replace(
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
     * The account's own name and home directory appear on nearly every line of
     * a build log, and both identify the customer.
     */
    private static function maskHomePaths(string $line, ?string $username): string
    {
        if ($username !== null && $username !== '') {
            $quoted = preg_quote($username, '#');
            $line = (string) preg_replace('#/home/' . $quoted . '\b#', '/home/' . self::ACCOUNT_PLACEHOLDER, $line);
            $line = (string) preg_replace('/\b' . $quoted . '\b/', self::ACCOUNT_PLACEHOLDER, $line);
        }

        // Any other account's home directory, in case one leaks into a message.
        return (string) preg_replace(
            '#/home/[A-Za-z0-9_.\-]+#',
            '/home/' . self::ACCOUNT_PLACEHOLDER,
            $line
        );
    }
}
