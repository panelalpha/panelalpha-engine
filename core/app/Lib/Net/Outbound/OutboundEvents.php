<?php

namespace App\Lib\Net\Outbound;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\OutboundEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The broker's report kept as events: one per scan block and per flood, SMTP or scan episode, by owner. An event the
 * report begins is told where the operator looks: the engine's log, the project's deploy log and monitoring.
 */
final class OutboundEvents
{
    public const MARK_KINDS = [OutboundEvent::KIND_FLOOD, OutboundEvent::KIND_SMTP, OutboundEvent::KIND_SCAN];

    /** @return list<OutboundEvent> the events this report began */
    public function sync(OutboundReport $report, Carbon $now): array
    {
        $begun = [];
        foreach ($report->blocks as $block) {
            $begun[] = $this->record($block, OutboundEvent::KIND_SCAN, $block['since'], $block['until'], $now->getTimestamp());
        }
        foreach ($report->marks as $mark) {
            $begun[] = $this->record($mark, $mark['kind'], $mark['since'], null, $mark['last']);
        }

        return array_values(array_filter($begun));
    }

    /** @param  array<string, int>  $settings */
    public function announce(OutboundEvent $event, array $settings): void
    {
        $line = OutboundNotice::line($event, $settings);
        Log::warning("Outbound limits: {$event->owner} ({$event->network} network, {$event->address}): {$line}");
        if (! User::query()->where('username', $event->owner)->exists()) {
            return;
        }

        DeployLogger::current($event->owner)?->warn($line);
        Telemetry::signal($event->owner, 'outbound-'.$event->kind, $line);
    }

    /** @param  array<string, string|int>  $entry */
    private function record(array $entry, string $kind, int $since, ?int $until, int $last): ?OutboundEvent
    {
        $startedAt = Carbon::createFromTimestampUTC($since);
        $fields = [
            'network' => (string) $entry['network'],
            'address' => (string) $entry['address'],
            'last_seen_at' => Carbon::createFromTimestampUTC($last),
            'until_at' => $until === null ? null : Carbon::createFromTimestampUTC($until),
        ];

        $known = OutboundEvent::query()
            ->where('owner', (string) $entry['owner'])
            ->where('kind', $kind)
            ->where('started_at', $startedAt)
            ->first();
        if ($known !== null) {
            $known->update($known->lifted_at === null ? $fields : ['last_seen_at' => $fields['last_seen_at']]);

            return null;
        }

        return OutboundEvent::query()->create(['owner' => (string) $entry['owner'], 'kind' => $kind, 'started_at' => $startedAt] + $fields);
    }
}
