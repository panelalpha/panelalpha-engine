<?php

namespace App\Lib\Net\Outbound;

use App\Models\OutboundEvent;

/**
 * The one line an outbound event leaves in the project's deploy log and the engine's log: what was stopped, the
 * setting behind it, and what to do.
 */
final class OutboundNotice
{
    /** @param  array<string, int>  $settings */
    public static function line(OutboundEvent $event, array $settings): string
    {
        $from = $event->network === OutboundEvent::NETWORK_BUILD ? 'A build of this project' : 'This project';

        return match ($event->kind) {
            OutboundEvent::KIND_SMTP => "{$from} tried to send mail straight to another server on port 25, which the server blocks"
                .' (EGRESS_BLOCK_SMTP). Send mail through the server\'s mail relay or a mail service on port 587 or 465.',
            OutboundEvent::KIND_FLOOD => "{$from} opened more than ".self::setting($settings, 'EGRESS_NEW_CONNECTIONS_PER_SECOND', 100)
                .' new outbound connections a second; the ones over that were dropped (EGRESS_NEW_CONNECTIONS_PER_SECOND).',
            default => $event->until_at === null
                ? "{$from} connected to more new addresses than the limit allows, which looks like a network scan; the"
                    .' connections over the limit were dropped (EGRESS_NEW_DESTINATIONS_PER_MINUTE, on ports 80 and 443'
                    .' EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE).'
                : "{$from} connected to more new addresses than the limit allows, which looks like a network scan, so its"
                    .' new outbound connections are blocked until '.$event->until_at->utc()->format('Y-m-d H:i').' UTC'
                    .' (EGRESS_NEW_DESTINATIONS_PER_MINUTE, on ports 80 and 443 EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE).'
                    .' The server\'s operator can lift it early.',
        };
    }

    /** @param  array<string, int>  $settings */
    private static function setting(array $settings, string $name, int $default): int
    {
        return $settings[$name] ?? $default;
    }
}
