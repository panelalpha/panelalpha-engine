<?php

namespace App\Lib\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Lib\Deploy\DeployLog\DeployLock;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Parks a push that finds the account's deploy lock held until {@see DeployLock::release()} runs it. Static and never
 * throwing, like {@see Telemetry}: every release calls it, most for an account with no hook at all.
 */
final class Coalescing
{
    /**
     * Whether this account has a deploy in progress of any kind, read from its deploy lock, not latest.json:
     * a killed deploy's stale `running` must not count, and a held lock promises a release is coming.
     */
    public static function isRunning(User $user): bool
    {
        return DeployLogger::isLockedFor($user->username);
    }

    /**
     * Park a delivery until the account's deploy lock is released, or run it at once if it already was.
     */
    public static function defer(HookDelivery $delivery, User $user): void
    {
        self::markPending($delivery);

        if (! self::isRunning($user) && self::claim($delivery->deploy_hook_id, $delivery->id)) {
            RunHookDelivery::dispatch($delivery->id);
        }
    }

    /**
     * Fold a delivery into whatever its hook is already waiting for; the one it replaces is superseded here and never
     * deployed, as both would deploy the same tracked branch's head.
     */
    public static function markPending(HookDelivery $delivery): void
    {
        try {
            DB::transaction(static function () use ($delivery): void {
                /** @var ?DeployHook $hook */
                $hook = DeployHook::query()->lockForUpdate()->find($delivery->deploy_hook_id);
                if ($hook === null) {
                    return;
                }

                $previousId = $hook->pending_delivery_id;
                if ($previousId !== null && $previousId !== $delivery->id) {
                    HookDelivery::whereKey($previousId)->update(['result' => HookDelivery::RESULT_SUPERSEDED]);
                }

                $hook->pending_delivery_id = $delivery->id;
                $hook->save();
            });
        } catch (\Throwable $e) {
            Log::debug("Could not mark hook delivery {$delivery->id} pending: {$e->getMessage()}");
        }
    }

    /**
     * This account's deploy lock was just released: run whatever coalesced meanwhile, exactly once per hook with
     * something pending.
     */
    public static function runPendingFor(string $username): void
    {
        try {
            $user = User::findByUsername($username);
            if ($user === null) {
                return;
            }

            $hooks = DeployHook::query()
                ->where('user_id', $user->id)
                ->whereNotNull('pending_delivery_id')
                ->get();

            foreach ($hooks as $hook) {
                $deliveryId = (int) $hook->pending_delivery_id;
                if (self::claim($hook->id, $deliveryId)) {
                    RunHookDelivery::dispatch($deliveryId);
                }
            }
        } catch (\Throwable $e) {
            Log::debug("Could not run pending hook deliveries for {$username}: {$e->getMessage()}");
        }
    }

    /**
     * Take a pending delivery off its hook if it is still the pending one. Only the caller that clears the
     * pointer gets true, so a delivery both halves reach at once is dispatched by exactly one of them.
     */
    private static function claim(int $hookId, int $deliveryId): bool
    {
        return DeployHook::whereKey($hookId)
            ->where('pending_delivery_id', $deliveryId)
            ->update(['pending_delivery_id' => null]) === 1;
    }
}
