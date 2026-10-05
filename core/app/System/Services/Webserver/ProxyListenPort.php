<?php

namespace App\System\Services\Webserver;

use App\Models\ProxyRule;
use App\System;
use Illuminate\Support\Facades\Log;

/**
 * Whether a proxy rule may listen on its port.
 *
 * sites-http runs on the host's network, so a rule's port is a host port. One
 * that something else already binds makes a reload keep the old config
 * (`still could not bind()`), and the next sites-http restart exit at bind,
 * taking every site on the host down with it.
 */
final class ProxyListenPort
{
    /** The webserver's own listeners: only an http rule shares them. */
    public const WEBSERVER = [80, 443];

    /** The engine's own listeners (scripts/firewall/ufw.sh managed_rules). */
    public const RESERVED = [21, 22, 2011, 2222, 30000, 30001, 30002, 30003, 30004, 30005, 30006, 30007, 30008, 30009];

    public function __construct(private System $system)
    {
    }

    /** Why a rule may not listen there, or null when it may. */
    public function refusal(string $transport, string $listenIp, int $port): ?string
    {
        if (in_array($port, self::WEBSERVER, true)) {
            return $transport === 'http' ? null : "Port {$port} is the webserver's own; only an http rule can listen on it.";
        }
        if (in_array($port, self::RESERVED, true)) {
            return "Port {$port} is reserved by the engine.";
        }

        $protocol = $transport === 'udp' ? 'udp' : 'tcp';
        $sameProtocol = $protocol === 'udp' ? ['udp'] : ['http', 'tcp'];
        /** @var list<ProxyRule> $rules */
        $rules = ProxyRule::query()
            ->where('enabled', true)
            ->where('listen_port', $port)
            ->whereIn('transport', $sameProtocol)
            ->get()
            ->all();
        foreach ($rules as $rule) {
            // http {} and stream {} cannot share a socket; rules of one kind share nginx's listener.
            if (($rule->transport === 'http') !== ($transport === 'http')) {
                return "Port {$port} is already used by proxy rule {$rule->id} ({$rule->transport}).";
            }
        }
        if ($rules !== []) {
            return null;
        }

        return $this->boundOnHost($protocol, $listenIp, $port)
            ? "Port {$port} is already in use on this host."
            : null;
    }

    /** A check that cannot run never refuses a rule. */
    private function boundOnHost(string $protocol, string $listenIp, int $port): bool
    {
        try {
            $listing = $this->system->execOnHost(['ss', '-Hln', $protocol === 'udp' ? '-u' : '-t']);
        } catch (\Throwable $e) {
            Log::warning('Could not list the host\'s listening sockets for a proxy rule: ' . $e->getMessage());
            return false;
        }

        foreach (self::listening($listing) as [$address, $boundPort]) {
            if ($boundPort === $port && self::overlaps($listenIp, $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Local address and port of each socket in `ss -Hln -t|-u` output.
     *
     * @return list<array{string, int}>
     */
    public static function listening(string $listing): array
    {
        $sockets = [];
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            // State Recv-Q Send-Q Local:Port Peer:Port
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 4 || !preg_match('/^(.*):(\d+)$/', $fields[3], $m)) {
                continue;
            }
            $address = preg_replace('/%.*$/', '', trim($m[1], '[]')) ?? '';
            $sockets[] = [$address, (int) $m[2]];
        }

        return $sockets;
    }

    /** Whether a rule on $listenIp and a socket bound to $address would collide. */
    private static function overlaps(string $listenIp, string $address): bool
    {
        if ($listenIp === '*' || in_array($address, ['*', '0.0.0.0', '::'], true)) {
            return true;
        }

        return @inet_pton($listenIp) === @inet_pton($address);
    }
}
