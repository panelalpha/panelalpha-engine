<?php

namespace App\Console\Commands\System;

use App\Lib\Net\Outbound\OutboundStatus;
use App\Models\OutboundEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * What the outbound limits stopped: the blocks and marks in force, or with --days every one of the last days, and the
 * settings in force.
 */
class EgressListCommand extends Command
{
    protected $signature = 'system:egress:list
        {--days= : Every event of the last N days instead of the ones in force}
        {--json : Print the events and settings as JSON}';

    protected $description = 'List the projects and builds the outbound limits stopped';

    public function handle(OutboundStatus $status): int
    {
        $now = Carbon::now('UTC');
        try {
            $report = $status->refresh($now);
        } catch (\Throwable $e) {
            $this->error('Could not read the outbound limits: '.$e->getMessage());

            return self::FAILURE;
        }

        $days = $this->option('days');
        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--days takes a whole number of days, 1 or more.');

            return self::FAILURE;
        }
        $events = $days === null ? $status->active($now) : $status->since($now->copy()->subDays((int) $days));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'active' => $report->active,
                'settings' => $report->settings,
                'events' => $events->map(static fn (OutboundEvent $event): array => OutboundStatus::describe($event, $now))->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($report->active
            ? 'The outbound limits are in place: '.$this->settings($report->settings).'.'
            : 'The outbound limits are not in place on this server; `pae-broker --host host.applyTenantNetworkFirewall --` applies them and says why they fail.');

        if ($events->isEmpty()) {
            $this->line($days === null ? 'Nothing is blocked or limited now.' : "Nothing was blocked or limited in the last {$days} day(s).");

            return self::SUCCESS;
        }

        $this->table(
            ['Project or build', 'Network', 'What', 'Address', 'Since (UTC)', 'Last seen / blocked until (UTC)', 'State'],
            $events->map(fn (OutboundEvent $event): array => $this->row($event, $now))->all(),
        );
        if ($events->contains(static fn (OutboundEvent $event): bool => $event->isBlock() && $event->isActive($now))) {
            $this->line('Lift a block early: pae system:egress:unblock <project>');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $settings */
    private function settings(array $settings): string
    {
        $smtp = ($settings['EGRESS_BLOCK_SMTP'] ?? 1) === 0 ? 'SMTP allowed' : 'SMTP to other servers blocked';
        $flood = ($settings['EGRESS_NEW_CONNECTIONS_PER_SECOND'] ?? 100) === 0
            ? 'no limit on new connections'
            : ($settings['EGRESS_NEW_CONNECTIONS_PER_SECOND'] ?? 100).' new connections a second';
        $other = $this->destinations($settings['EGRESS_NEW_DESTINATIONS_BURST'] ?? 60, $settings['EGRESS_NEW_DESTINATIONS_PER_MINUTE'] ?? 20);
        $web = $this->destinations($settings['EGRESS_WEB_NEW_DESTINATIONS_BURST'] ?? 600, $settings['EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE'] ?? 200);
        $minutes = $settings['EGRESS_SCAN_BLOCK_MINUTES'] ?? 30;
        $scan = $other === null && $web === null
            ? 'no scan limit'
            : ($other ?? 'any number of new addresses').', on ports 80 and 443 '.($web ?? 'any number').', '
                .($minutes === 0 ? 'the rest dropped' : "then blocked for {$minutes} minutes");

        return "{$smtp}, {$flood}, {$scan}";
    }

    private function destinations(int $burst, int $perMinute): ?string
    {
        return $burst === 0 || $perMinute === 0 ? null : "{$burst} new addresses at once, then {$perMinute} a minute";
    }

    /** @return list<string> */
    private function row(OutboundEvent $event, Carbon $now): array
    {
        $what = match ($event->kind) {
            OutboundEvent::KIND_SMTP => 'SMTP to another server',
            OutboundEvent::KIND_FLOOD => 'too many new connections',
            default => $event->isBlock() ? 'scan, blocked' : 'scan, dropped',
        };
        $state = match (true) {
            $event->lifted_at !== null => 'lifted by '.$event->lifted_by,
            $event->isActive($now) => 'in force',
            default => 'ended',
        };

        return [
            $event->owner,
            $event->network,
            $what,
            $event->address,
            $event->started_at->utc()->format('Y-m-d H:i'),
            ($event->until_at ?? $event->last_seen_at)->utc()->format('Y-m-d H:i'),
            $state,
        ];
    }
}
