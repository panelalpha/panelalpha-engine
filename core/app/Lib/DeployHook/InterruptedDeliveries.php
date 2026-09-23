<?php

namespace App\Lib\DeployHook;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Deliveries whose deploy died under them: the worker was killed, the core
 * container restarted, the job hit its timeout. Nothing writes their result --
 * the job's own `failed()` fires only once the queue gives up on the reserved
 * job, a day later ({@see config('queue.connections.database.retry_after')}) --
 * so without this they would read `queued` with no result until then.
 *
 * A delivery counts as interrupted when it recorded the deploy it started,
 * has no result, and that deploy is provably over without having finished:
 * no process holds the account's deploy lock, and `latest.json` either still
 * says that deploy is `running` (it was killed before finish()) or has moved
 * on to another deploy. A deploy that finished normally but whose delivery has
 * not been written yet leaves `latest.json` on its own id with a final status,
 * so it is left alone.
 */
final class InterruptedDeliveries
{
    public const DETAIL = 'The deploy was interrupted before it finished (the worker running it stopped). Push again or rebuild the project.';

    public static function settle(DeployHook $hook, string $username, ?int $exceptDeliveryId = null): void
    {
        try {
            if (DeployLogger::isLockedFor($username)) {
                return;
            }

            $candidates = HookDelivery::query()
                ->where('deploy_hook_id', $hook->id)
                ->whereNull('result')
                ->whereNotNull('deploy_id')
                ->when($exceptDeliveryId !== null, static fn ($query) => $query->whereKeyNot($exceptDeliveryId))
                ->get();
            if ($candidates->isEmpty()) {
                return;
            }

            $latest = DeployLogger::readLatestFor($username) ?? [];
            foreach ($candidates as $delivery) {
                if (!self::isOver($delivery->deploy_id, $latest)) {
                    continue;
                }

                HookDelivery::whereKey($delivery->id)
                    ->whereNull('result')
                    ->update([
                        'result' => HookDelivery::RESULT_DEPLOY_FAILED,
                        'detail' => self::DETAIL,
                    ]);
            }
        } catch (\Throwable $e) {
            Log::debug("Could not settle interrupted hook deliveries for {$username}: {$e->getMessage()}");
        }
    }

    /**
     * @param array<string, mixed> $latest
     */
    private static function isOver(string $deployId, array $latest): bool
    {
        if (($latest['id'] ?? null) !== $deployId) {
            return true;
        }

        return ($latest['status'] ?? null) === DeployLogger::STATUS_RUNNING;
    }
}
