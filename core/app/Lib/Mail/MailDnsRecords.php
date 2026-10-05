<?php

namespace App\Lib\Mail;

use Closure;

/**
 * The DNS records mail sent from a domain on this host needs to be accepted,
 * and, on request, whether they are published.
 *
 * The engine does not sign mail. DKIM comes from a relay (SendGrid, Amazon
 * SES, MailChannels, an SMTP server) that signs for the domain, and the relay
 * says which selector to publish.
 *
 * @psalm-type MailDnsRecord = array{
 *   type: string,
 *   host: ?string,
 *   record_type: string,
 *   expected: ?string,
 *   note: string,
 *   status?: string,
 *   found?: list<string>,
 *   problem?: ?string,
 * }
 */
final class MailDnsRecords
{
    /** The SPF include each relay asks its senders to publish. */
    public const RELAY_SPF_INCLUDES = [
        'sendgrid' => 'sendgrid.net',
        'mailchannels' => 'relay.mailchannels.net',
        'amazon_ses' => 'amazonses.com',
    ];

    public const DMARC_START = 'v=DMARC1; p=none';

    /**
     * @param Closure(string, int): (array<int, array<string, mixed>>|false) $resolve dns_get_record()'s shape
     */
    public function __construct(private Closure $resolve)
    {
    }

    public static function usingDns(): self
    {
        return new self(static fn (string $name, int $type): array|false => @dns_get_record($name, $type));
    }

    /** The relay as Exim is configured for it: '' when mail leaves this host directly. */
    public static function relay(string $smarthostProvider): string
    {
        return in_array($smarthostProvider, ['sendgrid', 'mailchannels', 'amazon_ses', 'smtp'], true)
            ? $smarthostProvider
            : '';
    }

    /**
     * The public addresses mail sent directly leaves from: the default IPv4,
     * through the NAT map when it is a private one, and the default IPv6.
     *
     * @param array<string, string> $localToPublic
     * @return list<string>
     */
    public static function hostAddresses(?string $ipv4, ?string $ipv6, array $localToPublic): array
    {
        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $ips = [];
        $ipv4 = trim((string) $ipv4);
        $ipv4 = $localToPublic[$ipv4] ?? $ipv4;
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $public) !== false) {
            $ips[] = $ipv4;
        }
        $ipv6 = trim((string) $ipv6);
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | $public) !== false) {
            $ips[] = $ipv6;
        }

        return $ips;
    }

    /**
     * The domain mail from $domain is sent as. A relay sender domain rewrites
     * every sender to `<local>_at_<domain>@<sender_domain>`, so then the
     * records belong to the sender domain, not to the site's.
     */
    public static function sendingDomain(string $domain, string $senderDomain): string
    {
        $senderDomain = strtolower(trim($senderDomain));

        return $senderDomain !== '' ? $senderDomain : strtolower($domain);
    }

    /**
     * @param string $relay the mail relay's smarthost_provider: '' sends directly
     * @param list<string> $hostIps the host's public addresses, for direct sending
     * @return list<MailDnsRecord>
     */
    public function expected(string $sendingDomain, string $relay, array $hostIps, ?string $dkimSelector = null): array
    {
        return [
            $this->spf($sendingDomain, $relay, $hostIps),
            $this->dkim($sendingDomain, $relay, $dkimSelector),
            [
                'type' => 'dmarc',
                'host' => '_dmarc.' . $sendingDomain,
                'record_type' => 'TXT',
                'expected' => self::DMARC_START,
                'note' => 'p=none only reports; tighten it to quarantine or reject once the reports show every sender passing.',
            ],
            [
                'type' => 'mx',
                'host' => $sendingDomain,
                'record_type' => 'MX',
                'expected' => null,
                'note' => 'This host receives no mail. Receivers check that a sending domain accepts mail, '
                    . 'so point its MX at the mailbox provider that should get replies and bounces.',
            ],
        ];
    }

    /**
     * {@see expected()} with each record looked up: status ok, missing, wrong
     * or unknown (the lookup failed or the engine cannot tell), what was
     * found, and what is wrong.
     *
     * @param list<string> $hostIps
     * @return list<MailDnsRecord>
     */
    public function check(string $sendingDomain, string $relay, array $hostIps, ?string $dkimSelector = null): array
    {
        $records = $this->expected($sendingDomain, $relay, $hostIps, $dkimSelector);
        foreach ($records as $i => $record) {
            $records[$i] = $record + match ($record['type']) {
                'spf' => $this->checkSpf($sendingDomain, $relay, $hostIps),
                'dkim' => $this->checkDkim($record['host'], $relay),
                'dmarc' => $this->checkDmarc($sendingDomain),
                default => $this->checkMx($sendingDomain),
            };
        }

        return $records;
    }

    /**
     * @param list<string> $hostIps
     * @return MailDnsRecord
     */
    private function spf(string $domain, string $relay, array $hostIps): array
    {
        $mechanisms = [];
        if ($relay === '') {
            foreach ($hostIps as $ip) {
                $mechanisms[] = (str_contains($ip, ':') ? 'ip6:' : 'ip4:') . $ip;
            }
        } elseif (isset(self::RELAY_SPF_INCLUDES[$relay])) {
            $mechanisms[] = 'include:' . self::RELAY_SPF_INCLUDES[$relay];
        }

        $note = 'One SPF record per domain: if it already has one, add '
            . ($mechanisms !== [] ? implode(' ', $mechanisms) : "the relay's include")
            . ' to it instead of publishing a second.';
        if ($relay === '' && $hostIps === []) {
            $note = 'This host has no public address on record, so the engine cannot say which address to authorise.';
        } elseif ($relay !== '' && $mechanisms === []) {
            $note = "Your SMTP relay's documentation names the include to put in this record. " . $note;
        }

        return [
            'type' => 'spf',
            'host' => $domain,
            'record_type' => 'TXT',
            'expected' => $mechanisms !== [] ? 'v=spf1 ' . implode(' ', $mechanisms) . ' ~all' : null,
            'note' => $note,
        ];
    }

    /** @return MailDnsRecord */
    private function dkim(string $domain, string $relay, ?string $selector): array
    {
        $note = $relay === ''
            ? 'The engine does not sign mail, so mail sent straight from this host carries no DKIM signature. '
                . 'Send through a relay that signs for this domain to get one.'
            : 'The relay signs. Publish the DKIM record its domain authentication page gives you, '
                . 'and pass its selector to check it.';

        return [
            'type' => 'dkim',
            'host' => $selector !== null ? "{$selector}._domainkey.{$domain}" : null,
            'record_type' => 'TXT',
            'expected' => null,
            'note' => $note,
        ];
    }

    /**
     * @param list<string> $hostIps
     * @return array{status: string, found: list<string>, problem: ?string}
     */
    private function checkSpf(string $domain, string $relay, array $hostIps): array
    {
        $spf = new SpfCheck($this->resolve);
        $found = $spf->records($domain);
        if ($found === false) {
            return self::outcome('unknown', [], "The TXT lookup for {$domain} failed.");
        }
        if ($found === []) {
            return self::outcome('missing', [], "{$domain} publishes no SPF record.");
        }
        if (count($found) > 1) {
            return self::outcome('wrong', $found, "{$domain} publishes more than one SPF record, which receivers treat as an error. Merge them into one.");
        }

        if ($relay === '') {
            if ($hostIps === []) {
                return self::outcome('unknown', $found, 'This host has no public address on record to check the record against.');
            }
            foreach ($hostIps as $ip) {
                $result = $spf->resultFor($domain, $ip);
                if ($result === 'temperror') {
                    return self::outcome('unknown', $found, ucfirst($spf->reason()) . '.');
                }
                if ($result !== 'pass') {
                    return self::outcome('wrong', $found, "Mail from this host ({$ip}) gets SPF {$result}: {$spf->reason()}.");
                }
            }

            return self::outcome('ok', $found, null);
        }

        $include = self::RELAY_SPF_INCLUDES[$relay] ?? null;
        if ($include === null) {
            return self::outcome('unknown', $found, 'The engine cannot tell which servers your SMTP relay sends from; check that its include is in this record.');
        }

        return match ($spf->includes($domain, $include)) {
            true => self::outcome('ok', $found, null),
            false => self::outcome('wrong', $found, "The record does not include:{$include}, so mail through the relay fails SPF."),
            null => self::outcome('unknown', $found, 'A lookup for one of the records it includes failed.'),
        };
    }

    /** @return array{status: string, found: list<string>, problem: ?string} */
    private function checkDkim(?string $host, string $relay): array
    {
        if ($relay === '') {
            return self::outcome('missing', [], 'Mail sent straight from this host is not signed.');
        }
        if ($host === null) {
            return self::outcome('unknown', [], 'No selector given; pass dkim_selector to check the record the relay asked for.');
        }

        $txt = ($this->resolve)($host, DNS_TXT);
        if ($txt === false) {
            return self::outcome('unknown', [], "The TXT lookup for {$host} failed.");
        }
        $found = array_values(array_filter(array_map(SpfCheck::txtValue(...), $txt), 'is_string'));
        if ($found === []) {
            return self::outcome('missing', [], "{$host} publishes no DKIM key.");
        }
        foreach ($found as $value) {
            if (preg_match('/(?:^|;)\s*p=([^;\s]+)/', $value) === 1) {
                return self::outcome('ok', $found, null);
            }
        }

        return self::outcome('wrong', $found, "{$host} has a record but no public key (p= is missing or empty, which revokes the key).");
    }

    /** @return array{status: string, found: list<string>, problem: ?string} */
    private function checkDmarc(string $domain): array
    {
        // Receivers fall back to a parent domain's policy (DMARCbis walks the
        // tree), so a record further up covers this one.
        $labels = explode('.', $domain);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            $txt = ($this->resolve)('_dmarc.' . $name, DNS_TXT);
            if ($txt === false) {
                return self::outcome('unknown', [], "The TXT lookup for _dmarc.{$name} failed.");
            }
            $found = [];
            foreach ($txt as $rr) {
                $value = SpfCheck::txtValue($rr);
                if ($value !== null && preg_match('/^v=DMARC1\s*(;|$)/i', $value) === 1) {
                    $found[] = $value;
                }
            }
            if ($found === []) {
                continue;
            }
            if (count($found) > 1) {
                return self::outcome('wrong', $found, "_dmarc.{$name} publishes more than one DMARC record, so receivers ignore them all.");
            }

            return self::outcome('ok', $found, null);
        }

        return self::outcome('missing', [], "Neither {$domain} nor a parent domain publishes a DMARC record.");
    }

    /** @return array{status: string, found: list<string>, problem: ?string} */
    private function checkMx(string $domain): array
    {
        $mx = ($this->resolve)($domain, DNS_MX);
        if ($mx === false) {
            return self::outcome('unknown', [], "The MX lookup for {$domain} failed.");
        }
        $found = [];
        foreach ($mx as $rr) {
            $found[] = ((int) ($rr['pri'] ?? 0)) . ' ' . rtrim((string) ($rr['target'] ?? ''), '.');
        }
        if ($found === []) {
            return self::outcome('missing', [], "{$domain} publishes no MX record.");
        }
        if (count($mx) === 1 && rtrim((string) ($mx[0]['target'] ?? ''), '.') === '') {
            return self::outcome('wrong', $found, "{$domain} publishes a null MX: it says it accepts no mail, and receivers may refuse mail from it.");
        }

        return self::outcome('ok', $found, null);
    }

    /**
     * @param list<string> $found
     * @return array{status: string, found: list<string>, problem: ?string}
     */
    private static function outcome(string $status, array $found, ?string $problem): array
    {
        return ['status' => $status, 'found' => $found, 'problem' => $problem];
    }
}
