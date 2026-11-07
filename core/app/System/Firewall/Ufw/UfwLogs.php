<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\FirewallLogEntry;
use App\System\Firewall\FirewallRule;

/**
 * ufw's kernel `[UFW BLOCK] IN= OUT= SRC= DST= PROTO= DPT=` lines and fail2ban's
 * `NOTICE [jail] Ban <address>` lines, in local time, to {@see FirewallLogEntry}.
 */
final class UfwLogs
{
    /**
     * An outbound block (OUT set, IN empty) names where it went, anything arriving where it came from;
     * the kernel writes IPv6 in full, so the address is compressed to match what the API is asked for.
     *
     * @return list<FirewallLogEntry>
     */
    public static function parseKernel(string $lines): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            if (! str_contains($line, '[UFW BLOCK]') || preg_match('/^(\S+)/', $line, $t) !== 1) {
                continue;
            }
            $time = self::time($t[1]);
            preg_match_all('/\b([A-Z]+)=(\S*)/', substr($line, (int) strpos($line, '[UFW BLOCK]')), $m, PREG_SET_ORDER);
            $f = [];
            foreach ($m as [, $key, $value]) {
                $f[$key] ??= $value;
            }
            if ($time === null || ! isset($f['SRC'], $f['DST'])) {
                continue;
            }
            $outbound = ($f['IN'] ?? '') === '' && ($f['OUT'] ?? '') !== '';
            $entries[] = new FirewallLogEntry(
                time: $time,
                type: FirewallLogEntry::BLOCKED,
                address: FirewallRule::normalizeAddress($outbound ? $f['DST'] : $f['SRC']),
                direction: $outbound ? 'out' : 'in',
                protocol: isset($f['PROTO']) ? strtolower($f['PROTO']) : null,
                port: ($f['DPT'] ?? '') !== '' ? $f['DPT'] : null,
            );
        }

        return $entries;
    }

    /**
     * @param  string  $offset  the host's UTC offset (`date +%:z`): fail2ban writes local time
     * @return list<FirewallLogEntry>
     */
    public static function parseFail2ban(string $lines, string $offset = '+00:00'): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            if (preg_match('/^(\d{4}-\d\d-\d\d) (\d\d:\d\d:\d\d)[,.]\d+ .*\[([^\]]+)\] (?:Restore )?(Ban|Unban) (\S+)/', $line, $m) !== 1) {
                continue;
            }
            $time = self::time("{$m[1]}T{$m[2]}{$offset}");
            if ($time === null) {
                continue;
            }
            $entries[] = new FirewallLogEntry(
                time: $time,
                type: $m[4] === 'Ban' ? FirewallLogEntry::BAN : FirewallLogEntry::UNBAN,
                address: FirewallRule::normalizeAddress($m[5]),
                jail: $m[3],
            );
        }

        return $entries;
    }

    private static function time(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
