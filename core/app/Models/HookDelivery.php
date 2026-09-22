<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One request a git host made to a Deploy Hook, and what became of it.
 *
 * Two fields, because two things happen at two times. `outcome` is what the
 * engine decided while the request was still open -- it is fixed on arrival
 * and the git host is answered from it. `result` is what the queued work then
 * achieved, and stays null for a delivery that queued nothing.
 *
 * @property int     $id
 * @property int     $deploy_hook_id
 * @property string  $provider
 * @property ?string $delivery_id
 * @property ?string $event
 * @property ?string $branch
 * @property ?string $commit
 * @property string  $outcome
 * @property ?string $reason
 * @property ?string $result
 * @property ?string $detail
 * @property ?string $deploy_id
 * @property Carbon  $created_at
 * @property Carbon  $updated_at
 */
class HookDelivery extends Model
{
    /** How many accepted deliveries a hook's history keeps; {@see pruneOldest()}. */
    public const KEEP_HISTORY = 20;

    /**
     * How many rejected deliveries a hook's history keeps, in a window of
     * their own. A rejected request needs only the URL, not the secret, so
     * it must never be able to push a real delivery out of the history.
     */
    public const KEEP_REJECTED = 5;

    /** Accepted, and a pull and rebuild is on the queue. */
    public const OUTCOME_QUEUED = 'queued';

    /** Authentic, and nothing to do: a ping, another branch, a tag. */
    public const OUTCOME_IGNORED = 'ignored';

    /** Not accepted: no supported provider, or a signature that does not hold. */
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * Accepted, but the project already had a deploy running -- from this
     * hook, another one, a manual rebuild, or the account's own creation. It
     * waits as the hook's `pending_delivery_id` rather than racing that
     * deploy; {@see \App\Lib\DeployHook\Coalescing} runs it once that deploy
     * finishes.
     */
    public const OUTCOME_COALESCED = 'coalesced';

    /** The pull and rebuild finished and the app is running the pushed code. */
    public const RESULT_DEPLOYED = 'deployed';

    /**
     * The deploy finished `partial`, the deploy log's own word for it: the app
     * was started from the pushed code but did not answer, or answered with
     * an error. `detail` says what. (The column holds 16 characters.)
     */
    public const RESULT_PARTIAL = 'partial';

    /** The pull or the rebuild failed. */
    public const RESULT_DEPLOY_FAILED = 'deploy_failed';

    /**
     * A Site Git checkout would not fast-forward. Git said no before touching
     * anything, so the checkout is as it was; `detail` names the paths.
     */
    public const RESULT_PULL_REFUSED = 'pull_refused';

    /**
     * A later delivery coalesced into the same pending slot before this one
     * ran, so it never gets its own deploy: the branch's current head at
     * follow-up time is what the later delivery would have deployed too.
     */
    public const RESULT_SUPERSEDED = 'superseded';

    protected $fillable = [
        'deploy_hook_id',
        'provider',
        'delivery_id',
        'event',
        'branch',
        'commit',
        'outcome',
        'reason',
        'result',
        'detail',
        'deploy_id',
    ];

    public function hook(): BelongsTo
    {
        return $this->belongsTo(DeployHook::class, 'deploy_hook_id');
    }

    /**
     * Drop everything beyond the newest {@see KEEP_HISTORY} accepted and the
     * newest {@see KEEP_REJECTED} rejected deliveries of one hook. Called
     * once, right after a new row is inserted, so a hook's history never
     * grows without bound while every delivery still gets its moment as the
     * newest row before ageing out.
     *
     * Two windows, so a flood of requests without the secret only ever
     * displaces its own kind. And a delivery that still has work coming --
     * the hook's pending one, or one queued or dispatched whose job has not
     * written a result yet -- is never dropped, whatever its age: its job
     * would find no row and the push would vanish without a trace.
     */
    public static function pruneOldest(int $hookId, int $keep = self::KEEP_HISTORY, int $keepRejected = self::KEEP_REJECTED): void
    {
        $stale = static fn (bool $rejected, int $window) => self::query()
            ->where('deploy_hook_id', $hookId)
            ->where('outcome', $rejected ? '=' : '!=', self::OUTCOME_REJECTED)
            ->orderByDesc('id')
            ->skip($window)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        $staleIds = $stale(false, $keep)->merge($stale(true, $keepRejected));
        if ($staleIds->isEmpty()) {
            return;
        }

        $pendingId = DeployHook::whereKey($hookId)->value('pending_delivery_id');

        self::whereIn('id', $staleIds)
            ->when($pendingId !== null, static fn ($query) => $query->whereKeyNot($pendingId))
            ->where(static fn ($query) => $query
                ->whereNotIn('outcome', [self::OUTCOME_QUEUED, self::OUTCOME_COALESCED])
                ->orWhereNotNull('result'))
            ->delete();
    }
}
