<?php

namespace App\System\Services\Webserver;

use App\Lib\Domains\HttpsPorts;
use App\Models\ProxyRule;
use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;

/**
 * Whether a proxy rule may listen on its port: sites-http runs on the host's network, so a port something else
 * binds keeps the old config on reload and stops the next sites-http restart, and every site, at bind.
 */
final class ProxyListenPort
{
    /** The webserver's own listeners: only an http rule shares them. */
    public const WEBSERVER = [80, 443];

    /** The engine's own listeners (scripts/firewall/ufw.sh managed_rules). */
    public const RESERVED = [21, 22, 2011, 2222, 30000, 30001, 30002, 30003, 30004, 30005, 30006, 30007, 30008, 30009];

    public function __construct(private System $system) {}

    /**
     * Why a rule may not listen there, or null when it may. http {} and stream {} cannot share a socket;
     * rules of one kind share nginx's listener.
     */
    public function refusal(string $transport, string $listenIp, int $port): ?string
    {
        if (in_array($port, self::WEBSERVER, true)) {
            return $transport === 'http' ? null : "Port {$port} is the webserver's own; only an http rule can listen on it.";
        }
        if (in_array($port, self::RESERVED, true)) {
            return "Port {$port} is reserved by the engine.";
        }
        $httpsNames = $transport === 'udp' ? [] : HttpsPorts::namesOn($port);
        if ($httpsNames !== []) {
            return "Port {$port} carries HTTPS for {$httpsNames[0]}.";
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

    /**
     * Why a stored, disabled rule may not be switched on: it takes its port again, an http rule its name on that
     * port and a tcp/udp rule its address, where nginx serves one rule.
     */
    public function switchOnRefusal(ProxyRule $rule): ?string
    {
        $listenIp = $rule->listen_ip ?? '*';
        $port = (int) $rule->listen_port;

        return $this->refusal($rule->transport, $listenIp, $port)
            ?? ProxyRuleDuplicate::ruleRefusal($rule->transport, $listenIp, $port, $rule->server_name, (int) $rule->id);
    }

    /** A check that cannot run never refuses a rule. */
    public function boundOnHost(string $protocol, string $listenIp, int $port): bool
    {
        try {
            $listing = $this->system->execOnHost(Privileged::network()->listeningSockets($protocol === 'udp' ? 'udp' : 'tcp'));
        } catch (\Throwable $e) {
            Log::warning('Could not list the host\'s listening sockets for a proxy rule: '.$e->getMessage());

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
     * Local address and port of each socket in `ss -Hln -t|-u` output: State Recv-Q Send-Q Local:Port Peer:Port.
     *
     * @return list<array{string, int}>
     */
    public static function listening(string $listing): array
    {
        $sockets = [];
        foreach (preg_split('/\R/', $listing) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) < 4 || ! preg_match('/^(.*):(\d+)$/', $fields[3], $m)) {
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
