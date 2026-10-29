<?php

namespace App\Lib\Hub;

use App\Models\HubConnection;
use App\System\Firewall\ExemptsApiCallers;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\TrustedAddress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the addresses PanelAlpha Connect calls from out of the engine-api jail while connected, or a
 * replaced token's 401s ban them. Learnt from its calls: the Hub's name resolves to a CDN, and Connect
 * calls from more than one address.
 */
final class HubCallerAddress
{
    public const COMMENT = 'PanelAlpha Connect';

    /** Statuses that say nothing about who holds the token. */
    public const REFUSED = [401, 403];

    /** Addresses one connection keeps exempt; a new one pushes out the one seen least recently. */
    public const MAX_ADDRESSES = 8;

    /** An address Connect has not called from for this long is no longer exempt. */
    public const FORGET_AFTER_DAYS = 7;

    /** A known address's last-seen time is rewritten at most this often. */
    public const REFRESH_SECONDS = 3600;

    /** A call the connection's token got through, from $ip. Only a change of the addresses touches the jail, after the answer. */
    public static function seen(string $connectionId, string $ip): void
    {
        $connection = HubConnection::query()->where('connection_id', $connectionId)->first();
        if ($connection === null || ! self::isDue($connection->caller_ips ?? [], $ip, Carbon::now('UTC'))) {
            return;
        }
        if (self::record($connectionId, $ip)) {
            self::syncAfterResponse();
        }
    }

    /**
     * $known with $ip seen at $now: newest first, nothing older than FORGET_AFTER_DAYS, at most MAX_ADDRESSES.
     *
     * @param  array<string, string>  $known  address => last seen
     * @return array<string, string>
     */
    public static function withAddress(array $known, string $ip, Carbon $now): array
    {
        $known[$ip] = self::stamp($now);

        return self::current($known, $now);
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

    /** @param array<string, string> $known */
    private static function isDue(array $known, string $ip, Carbon $now): bool
    {
        return ! isset($known[$ip])
            || $known[$ip] < self::stamp($now->copy()->subSeconds(self::REFRESH_SECONDS))
            || count(self::current($known, $now)) < count($known);
    }

    /** Under the connection's row lock, so calls from two new addresses at once both count. True when the addresses changed. */
    private static function record(string $connectionId, string $ip): bool
    {
        return DB::transaction(static function () use ($connectionId, $ip): bool {
            $connection = HubConnection::query()->where('connection_id', $connectionId)->lockForUpdate()->first();
            if ($connection === null) {
                return false;
            }
            $before = $connection->caller_ips ?? [];
            $after = self::withAddress($before, $ip, Carbon::now('UTC'));
            $connection->forceFill(['caller_ips' => $after])->save();

            return self::addressesOf($after) !== self::addressesOf($before);
        });
    }

    /**
     * @param  array<string, string>  $known
     * @return array<string, string>
     */
    private static function current(array $known, Carbon $now): array
    {
        $oldest = self::stamp($now->copy()->subDays(self::FORGET_AFTER_DAYS));
        $kept = array_filter($known, static fn (mixed $seen): bool => is_string($seen) && $seen >= $oldest);
        arsort($kept, SORT_STRING);

        return array_slice($kept, 0, self::MAX_ADDRESSES, true);
    }

    /**
     * @param  array<string, string>  $known
     * @return list<string>
     */
    private static function addressesOf(array $known): array
    {
        $addresses = array_map('strval', array_keys($known));
        sort($addresses, SORT_STRING);

        return $addresses;
    }

    private static function stamp(Carbon $at): string
    {
        return $at->copy()->setTimezone('UTC')->toIso8601ZuluString();
    }

    /**
     * Read under the list's lock, so a connection that changes meanwhile is not written over.
     *
     * @return list<TrustedAddress>
     */
    private static function connectedAddresses(): array
    {
        $now = Carbon::now('UTC');
        $addresses = HubConnection::query()
            ->where('status', HubConnection::STATUS_CONNECTED)
            ->get(['caller_ips'])
            ->flatMap(static fn (HubConnection $c): array => self::addressesOf(self::current($c->caller_ips ?? [], $now)))
            ->unique()
            ->all();
        sort($addresses, SORT_STRING);

        return array_map(static fn (string $ip): TrustedAddress => new TrustedAddress($ip, self::COMMENT), $addresses);
    }
}
