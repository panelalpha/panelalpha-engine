<?php

namespace App\Lib\Net\Outbound;

use App\Models\OutboundEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The outbound limits as the operator sees them: brought up to date from the broker, listed, and an owner's block
 * lifted. What `pae system:egress:*` and `/system/egress` answer with.
 */
final class OutboundStatus
{
    public const LIFTED_BY_CLI = 'pae';

    public const LIFTED_BY_API = 'api';

    public function __construct(
        private readonly OutboundLimits $limits = new OutboundLimits,
        private readonly OutboundEvents $events = new OutboundEvents,
    ) {}

    /** The broker's report, its new events kept and announced. */
    public function refresh(Carbon $now): OutboundReport
    {
        $report = $this->limits->report();
        foreach ($this->events->sync($report, $now) as $begun) {
            $this->events->announce($begun, $report->settings);
        }

        return $report;
    }

    /** @return Collection<int, OutboundEvent> */
    public function since(Carbon $since): Collection
    {
        return OutboundEvent::query()->since($since)->orderByDesc('started_at')->get();
    }

    /** @return Collection<int, OutboundEvent> */
    public function active(Carbon $now): Collection
    {
        return $this->since($now->copy()->subSeconds(OutboundEvent::QUIET_SECONDS))
            ->filter(static fn (OutboundEvent $event): bool => $event->isActive($now))
            ->values();
    }

    /** @return list<string> the addresses that lost their block; none when the owner had none */
    public function unblock(string $owner, string $by, Carbon $now): array
    {
        $addresses = $this->limits->unblock($owner);
        OutboundEvent::query()
            ->where('owner', $owner)
            ->where('kind', OutboundEvent::KIND_SCAN)
            ->whereNull('lifted_at')
            ->where(static fn ($query) => $query->where('until_at', '>', $now)->orWhere('last_seen_at', '>', $now->copy()->subSeconds(OutboundEvent::QUIET_SECONDS)))
            ->update(['lifted_at' => $now, 'lifted_by' => $by]);

        return $addresses;
    }

    /** @return array<string, mixed> */
    public static function describe(OutboundEvent $event, Carbon $now): array
    {
        return [
            'owner' => $event->owner,
            'network' => $event->network,
            'kind' => $event->kind,
            'address' => $event->address,
            'started_at' => $event->started_at->utc()->toIso8601String(),
            'last_seen_at' => $event->last_seen_at->utc()->toIso8601String(),
            'blocked_until' => $event->until_at?->utc()->toIso8601String(),
            'active' => $event->isActive($now),
            'lifted_at' => $event->lifted_at?->utc()->toIso8601String(),
            'lifted_by' => $event->lifted_by,
        ];
    }
}
