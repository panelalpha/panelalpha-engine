<?php

namespace App\Lib\Mail;

use Closure;

/**
 * Enough of SPF (RFC 7208) to tell whether a domain's record lets a given
 * address send, or reaches a relay's include. Macros, ptr and exists are not
 * evaluated: they never match here, so a record that relies on them can read
 * as not authorising when a receiver would say it does.
 */
final class SpfCheck
{
    /** RFC 7208 4.6.4: more DNS-querying terms than this is a permerror. */
    private const MAX_LOOKUPS = 10;

    private int $lookups = 0;

    /** Why the last result is what it is, for the operator. */
    private string $reason = '';

    /**
     * @param Closure(string, int): (array<int, array<string, mixed>>|false) $resolve dns_get_record()'s shape
     */
    public function __construct(private Closure $resolve)
    {
    }

    /**
     * The SPF records published at $domain, or false when the lookup failed.
     *
     * @return list<string>|false
     */
    public function records(string $domain): array|false
    {
        $txt = ($this->resolve)($domain, DNS_TXT);
        if ($txt === false) {
            return false;
        }

        $records = [];
        foreach ($txt as $rr) {
            $value = self::txtValue($rr);
            if ($value !== null && preg_match('/^v=spf1(\s|$)/i', $value) === 1) {
                $records[] = $value;
            }
        }

        return $records;
    }

    /**
     * check_host() for mail from $domain sent by $ip: pass, fail, softfail,
     * neutral, none, permerror or temperror.
     */
    public function resultFor(string $domain, string $ip): string
    {
        $this->lookups = 0;
        $this->reason = '';

        return $this->checkHost(strtolower($domain), $ip);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Whether the record at $domain reaches include:$target, directly or
     * through its own includes and redirects; null when a lookup failed.
     */
    public function includes(string $domain, string $target): ?bool
    {
        $this->lookups = 0;

        return $this->reaches(strtolower($domain), strtolower($target));
    }

    private function reaches(string $domain, string $target): ?bool
    {
        $records = $this->records($domain);
        if ($records === false) {
            return null;
        }
        if (count($records) !== 1) {
            return false;
        }

        foreach (self::terms($records[0]) as $term) {
            if (preg_match('/^[+?~-]?include:(.+)$/i', $term, $m) !== 1
                && preg_match('/^redirect=(.+)$/i', $term, $m) !== 1) {
                continue;
            }
            $next = strtolower(rtrim($m[1], '.'));
            if ($next === $target) {
                return true;
            }
            if (++$this->lookups > self::MAX_LOOKUPS || str_contains($next, '%')) {
                continue;
            }
            $found = $this->reaches($next, $target);
            if ($found !== false) {
                return $found;
            }
        }

        return false;
    }

    private function checkHost(string $domain, string $ip): string
    {
        $records = $this->records($domain);
        if ($records === false) {
            $this->reason = "the SPF lookup for {$domain} failed";

            return 'temperror';
        }
        if ($records === []) {
            $this->reason = "{$domain} publishes no SPF record";

            return 'none';
        }
        if (count($records) > 1) {
            $this->reason = "{$domain} publishes more than one SPF record, which receivers treat as an error";

            return 'permerror';
        }

        $redirect = null;
        foreach (self::terms($records[0]) as $term) {
            if (preg_match('/^redirect=(.+)$/i', $term, $m) === 1) {
                $redirect = strtolower(rtrim($m[1], '.'));
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_.-]*=/i', $term) === 1) {
                continue;
            }

            $qualifier = '+';
            if (str_contains('+-~?', $term[0])) {
                $qualifier = $term[0];
                $term = substr($term, 1);
            }
            if (preg_match('#^(all|include|a|mx|ptr|ip4|ip6|exists)(?::([^/]+))?(?:/(\d+))?(?://(\d+))?$#i', $term, $m) !== 1) {
                $this->reason = "{$domain}'s SPF record has a term it cannot use: {$term}";

                return 'permerror';
            }
            $match = $this->matches(strtolower($m[1]), $m[2] ?? '', $m[3] ?? '', $m[4] ?? '', $domain, $ip);
            if (is_string($match)) {
                return $match;
            }
            if ($match) {
                $this->reason = "{$domain}'s SPF record matches {$ip} on {$qualifier}{$term}";

                return match ($qualifier) {
                    '-' => 'fail',
                    '~' => 'softfail',
                    '?' => 'neutral',
                    default => 'pass',
                };
            }
        }

        if ($redirect !== null) {
            if (++$this->lookups > self::MAX_LOOKUPS) {
                return $this->tooManyLookups($domain);
            }
            $result = $this->checkHost($redirect, $ip);

            return $result === 'none' ? 'permerror' : $result;
        }

        $this->reason = "nothing in {$domain}'s SPF record matches {$ip}";

        return 'neutral';
    }

    /**
     * @return bool|string whether the mechanism matches, or a result that ends the check
     */
    private function matches(string $name, string $arg, string $cidr4, string $cidr6, string $domain, string $ip): bool|string
    {
        if (str_contains($arg, '%')) {
            return false;
        }
        $target = $arg !== '' ? strtolower(rtrim($arg, '.')) : $domain;
        $v6 = str_contains($ip, ':');
        $prefix = $v6 ? ($name === 'ip6' ? $cidr4 : $cidr6) : $cidr4;

        switch ($name) {
            case 'all':
                return true;
            case 'ip4':
            case 'ip6':
                return ($name === 'ip6') === $v6 && self::inCidr($ip, $arg, $prefix);
            case 'include':
                if (++$this->lookups > self::MAX_LOOKUPS) {
                    return $this->tooManyLookups($domain);
                }
                $result = $this->checkHost($target, $ip);
                if ($result === 'none') {
                    $this->reason = "{$domain} includes {$target}, which publishes no SPF record";

                    return 'permerror';
                }

                return in_array($result, ['temperror', 'permerror'], true) ? $result : $result === 'pass';
            case 'a':
            case 'mx':
                if (++$this->lookups > self::MAX_LOOKUPS) {
                    return $this->tooManyLookups($domain);
                }
                $hosts = [$target];
                if ($name === 'mx') {
                    $mx = ($this->resolve)($target, DNS_MX);
                    $hosts = array_slice(array_map(static fn (array $rr): string => (string) ($rr['target'] ?? ''), $mx ?: []), 0, 10);
                }
                foreach ($hosts as $host) {
                    foreach (($this->resolve)($host, $v6 ? DNS_AAAA : DNS_A) ?: [] as $rr) {
                        $address = (string) ($rr[$v6 ? 'ipv6' : 'ip'] ?? '');
                        if ($address !== '' && self::inCidr($ip, $address, $prefix)) {
                            return true;
                        }
                    }
                }

                return false;
            default:
                // ptr and exists: counted, never matched.
                if (++$this->lookups > self::MAX_LOOKUPS) {
                    return $this->tooManyLookups($domain);
                }

                return false;
        }
    }

    private function tooManyLookups(string $domain): string
    {
        $this->reason = "{$domain}'s SPF record needs more than " . self::MAX_LOOKUPS . ' DNS lookups, which receivers treat as an error';

        return 'permerror';
    }

    /** @return list<string> */
    private static function terms(string $record): array
    {
        return array_values(array_slice(preg_split('/\s+/', trim($record)) ?: [], 1));
    }

    public static function inCidr(string $ip, string $network, string $prefix): bool
    {
        $a = @inet_pton($ip);
        $b = @inet_pton($network);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $prefix === '' ? strlen($a) * 8 : min((int) $prefix, strlen($a) * 8);
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** @param array<string, mixed> $rr */
    public static function txtValue(array $rr): ?string
    {
        if (isset($rr['entries']) && is_array($rr['entries'])) {
            return implode('', array_map('strval', $rr['entries']));
        }

        return isset($rr['txt']) ? (string) $rr['txt'] : null;
    }
}
