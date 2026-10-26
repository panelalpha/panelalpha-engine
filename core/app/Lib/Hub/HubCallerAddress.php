<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\System\Firewall\ExemptsApiCallers;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\TrustedAddress;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the address PanelAlpha Connect calls from out of the engine-api jail while connected, or a
 * replaced token's 401s ban it. Learnt from its calls: the Hub's name resolves to a CDN.
 */
final class HubCallerAddress
{
    public const COMMENT = 'PanelAlpha Connect';
    /** Statuses that say nothing about who holds the token. */
    public const REFUSED = [401, 403];

    /** A call the connection's token got through, from $ip. Only a new address changes the jail, after the answer. */
    public static function seen(string $connectionId, string $ip): void
    {
        $changed = HubConnection::query()
            ->where('connection_id', $connectionId)
            ->where(fn ($q) => $q->whereNull('caller_ip')->orWhere('caller_ip', '!=', $ip))
            ->update(['caller_ip' => $ip]);
        if ($changed > 0) {
            self::syncAfterResponse();
        }
    }

    public static function syncAfterResponse(): void
    {
        app()->terminating(static fn () => self::sync());
    }

    /** Exempt exactly the addresses the stored connections call from. Never throws. */
    public static function sync(): void
    {
        try {
            $firewall = FirewallFactory::default();
            if (! $firewall instanceof ExemptsApiCallers) {
                Log::warning("PanelAlpha Connect's address is not exempt from API bans: the {$firewall->name()} firewall cannot exempt it.");

                return;
            }
            $firewall->exemptApiCallers(self::connectedAddresses(...));
        } catch (\Throwable $e) {
            Log::warning("Could not update PanelAlpha Connect's exemption from API bans: ".$e->getMessage());
        }
    }

    /**
     * Read under the list's lock, so a connection that changes meanwhile is not written over.
     *
     * @return list<TrustedAddress>
     */
    private static function connectedAddresses(): array
    {
        return array_map(
            static fn (string $ip): TrustedAddress => new TrustedAddress($ip, self::COMMENT),
            HubConnection::query()
                ->where('status', HubConnection::STATUS_CONNECTED)
                ->whereNotNull('caller_ip')
                ->orderBy('caller_ip')
                ->distinct()
                ->pluck('caller_ip')
                ->all(),
        );
    }
}
