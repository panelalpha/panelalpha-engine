<?php

namespace App\Lib\DeployHook;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fails deliveries whose deploy died under them, which would read `queued` until the queue's retry_after.
 * A deploy is provably over unfinished when no lock is held and `latest.json` says `running` or another id.
 */
final class InterruptedDeliveries
{
    public const DETAIL = 'The deploy was interrupted before it finished (the worker running it stopped). Push again or rebuild the project.';

    public static function settle(DeployHook $hook, string $username, ?int $exceptDeliveryId = null): void
    {
        try {
            self::settleIfUnlocked($hook, $username, $exceptDeliveryId);
        } catch (\Throwable $e) {
            Log::debug("Could not settle interrupted hook deliveries for {$username}: {$e->getMessage()}");
        }
    }

    private static function settleIfUnlocked(DeployHook $hook, string $username, ?int $exceptDeliveryId): void
    {
        if (DeployLogger::isLockedFor($username)) {
            return;
        }

        $candidates = self::unsettled($hook, $exceptDeliveryId);
        if ($candidates->isEmpty()) {
            return;
        }

        $latest = DeployLogger::readLatestFor($username) ?? [];
        foreach ($candidates as $delivery) {
            if (! self::isOver($delivery->deploy_id, $latest)) {
                continue;
            }

            self::markInterrupted($delivery);
        }
    }

    /** @return Collection<int, HookDelivery> */
    private static function unsettled(DeployHook $hook, ?int $exceptDeliveryId): Collection
    {
        return HookDelivery::query()
            ->where('deploy_hook_id', $hook->id)
            ->whereNull('result')
            ->whereNotNull('deploy_id')
            ->when($exceptDeliveryId !== null, static fn ($query) => $query->whereKeyNot($exceptDeliveryId))
            ->get();
    }

    private static function markInterrupted(HookDelivery $delivery): void
    {
        HookDelivery::whereKey($delivery->id)
            ->whereNull('result')
            ->update([
                'result' => HookDelivery::RESULT_DEPLOY_FAILED,
                'detail' => self::DETAIL,
            ]);
    }

    /**
     * @param  array<string, mixed>  $latest
     */
    private static function isOver(string $deployId, array $latest): bool
    {
        if (($latest['id'] ?? null) !== $deployId) {
            return true;
        }

        return ($latest['status'] ?? null) === DeployLogger::STATUS_RUNNING;
    }
}
