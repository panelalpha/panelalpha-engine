<?php

namespace App\Lib\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What happens to a Hook Delivery that arrives while its project already has
 * a deploy running, and how that project's next deploy hears about it.
 *
 * A push is never raced against the deploy already in progress -- whatever
 * kind of deploy that is: a manual rebuild, the deploy at account creation, a
 * Checkout Rebuild after someone else's pull, or an earlier push. It waits.
 * {@see defer()} is the arrival half, called from {@see HookReceiver} before
 * it answers the git host and from {@see DeliveryRunner} when a job lost the
 * race at the wire. {@see runPendingFor()} is the departure half, called from
 * {@see DeployLogger::finish()} -- the one place every one of those deploy
 * kinds passes through when it ends, success, partial, failure or cancel
 * alike.
 *
 * The two halves race: a deploy can finish between a delivery seeing it
 * running and the delivery being marked pending. So the arrival half checks
 * again once the mark is down, and whichever half gets there first claims the
 * delivery with a conditional update ({@see claim()}) -- it is dispatched
 * exactly once, never zero times, never twice.
 *
 * Only the most recent arrival survives: a second push while one is already
 * pending supersedes the first on the spot, because both would redeploy the
 * same tracked branch and only its current head at follow-up time matters.
 *
 * Static, like {@see \App\Lib\Deploy\Telemetry\Telemetry}: `finish()` calls it
 * unconditionally, for every deploy of every project, most of which have no
 * hook at all, so it must never throw and never cost a caller a dependency it
 * has no other reason to take.
 */
final class Coalescing
{
    /**
     * Whether this account has a deploy in progress, of any kind -- read from
     * its deploy lock, not latest.json. A status left `running` by a deploy
     * that was killed before finish() must not count: no finish() would ever
     * end the wait. And a push that is parked must be sure a finish() is
     * still coming, which is exactly what a held lock promises.
     */
    public static function isRunning(User $user): bool
    {
        return DeployLogger::isLockedFor($user->username);
    }

    /**
     * Park a delivery until the account's running deploy finishes -- or, if
     * that deploy finished while this was being decided, run it now.
     */
    public static function defer(HookDelivery $delivery, User $user): void
    {
        self::markPending($delivery);

        if (!self::isRunning($user) && self::claim($delivery->deploy_hook_id, $delivery->id)) {
            RunHookDelivery::dispatch($delivery->id);
        }
    }

    /**
     * Fold a delivery into whatever its hook is already waiting for. The
     * delivery it replaces, if any, never gets its own deploy -- it is
     * superseded here, not when the follow-up eventually runs.
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
     * A deploy for this account just finished. Run whatever coalesced while
     * it was in progress -- exactly once per hook that has something
     * pending, and nothing when there is none.
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
     * Take a pending delivery off its hook, if it is still the pending one.
     * Only the caller that clears the pointer gets true, so a delivery both
     * halves reach at once is dispatched by exactly one of them.
     */
    private static function claim(int $hookId, int $deliveryId): bool
    {
        return DeployHook::whereKey($hookId)
            ->where('pending_delivery_id', $deliveryId)
            ->update(['pending_delivery_id' => null]) === 1;
    }
}
