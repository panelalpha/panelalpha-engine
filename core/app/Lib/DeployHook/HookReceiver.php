<?php

namespace App\Lib\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Takes one request a git host made to a hook and decides what it means.
 *
 * Everything here happens while the host is waiting, so it is only reading a
 * header, checking an HMAC or a token and writing a row. Nothing that touches git or
 * Docker runs here: a push that should deploy becomes a queued job and the
 * host is told 202 straight away, long before a build finishes.
 *
 * The order of the checks is the order of how much each has to be trusted:
 *
 *  0. Has this hook already failed to prove who is sending it too many times
 *     in the last minute -- an unrecognised provider or a bad signature? 429,
 *     and nothing below is checked -- a leaked or guessed URL cannot be used
 *     to burn CPU or fill the deliveries table forever.
 *  1. Who claims to be sending this? Nobody we support: 400, and it counts
 *     against that hook's throttle -- this costs nothing to send and nothing
 *     to reject, so it is the cheapest way to flood a leaked URL.
 *  2. Can they prove it with the hook's secret? No: 401, and it counts
 *     against that hook's throttle too. Nothing about the body has been
 *     looked at yet, and nothing the request said is written down beyond the
 *     provider's name.
 *  3. Only now is the body read, because only now is it the sender's.
 *  4. Have we already seen this delivery, or this signed push body? A
 *     redelivery is a no-op, and so is a replay under a new delivery id.
 *  5. What does it say, and is it for the branch this checkout tracks? A push
 *     can name several refs (Bitbucket sends them in one delivery); it deploys,
 *     once, if any of them is the tracked branch moving forward.
 */
class HookReceiver
{
    /** Unverified requests (no recognised provider, or a bad signature) a single hook tolerates in a minute before 429. */
    private const int MAX_UNVERIFIED_REQUESTS_PER_MINUTE = 30;

    /** How long a hook remembers a signed push body it received; a replay of an older one is acted on again. */
    private const int DIGEST_RETENTION_DAYS = 90;

    /** @var list<HookProvider> */
    private array $providers;

    /**
     * @param  list<HookProvider>|null  $providers
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? [
            new GithubProvider,
            new GitlabProvider,
            new BitbucketCloudProvider,
            new BitbucketDataCenterProvider,
        ];
    }

    public function receive(DeployHook $hook, Request $request): HookResponse
    {
        if ($this->tooManyUnverified($hook)) {
            return new HookResponse(429, ['message' => 'Too many failed signatures for this hook.']);
        }

        $provider = $this->recognise($request);
        if ($provider === null) {
            return $this->rejectUnproven($hook, 'unknown', 'unsupported provider', 400, 'Unsupported provider.');
        }

        $secret = $hook->secret();
        if ($secret === null || ! $this->proves($hook, $provider, $request, $secret)) {
            return $this->rejectUnproven($hook, $provider->name(), 'missing or invalid signature', 401, 'Invalid signature.');
        }

        try {
            $event = $provider->parse($request);
        } catch (InvalidPayload) {
            $this->record($hook, $provider->name(), null, HookDelivery::OUTCOME_REJECTED, 'invalid payload');

            return new HookResponse(400, ['message' => 'Invalid payload.']);
        }

        if ($event->deliveryId !== null && $this->alreadyRecorded($hook, $event->deliveryId)) {
            return $this->duplicate($event);
        }

        return $this->accept($hook, $provider, $request, $event);
    }

    /**
     * Once a signed delivery has reached a hook, only signed ones are accepted, so a static
     * token captured from one of them cannot be sent on its own with any body.
     */
    private function proves(DeployHook $hook, HookProvider $provider, Request $request, string $secret): bool
    {
        if (! $provider->verify($request, $secret)) {
            return false;
        }
        if (! $provider->signsTheBody($request, $secret)) {
            return ! $hook->expectsSignatures();
        }

        $hook->rememberSignedDelivery();

        return true;
    }

    /**
     * A throttled request is not recorded: it is the flood itself, and a row per
     * request would let it fill the table it is burning CPU against.
     */
    private function tooManyUnverified(DeployHook $hook): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey($hook), self::MAX_UNVERIFIED_REQUESTS_PER_MINUTE);
    }

    /**
     * Records a request that did not prove its sender and counts it against the hook's throttle.
     * An unrecognised provider costs the sender nothing to fake, so it counts like a bad signature.
     */
    private function rejectUnproven(DeployHook $hook, string $provider, string $reason, int $status, string $message): HookResponse
    {
        RateLimiter::hit($this->throttleKey($hook), 60);
        $this->record($hook, $provider, null, HookDelivery::OUTCOME_REJECTED, $reason);

        return new HookResponse($status, ['message' => $message]);
    }

    private function accept(DeployHook $hook, HookProvider $provider, Request $request, HookEvent $event): HookResponse
    {
        [$outcome, $reason, $change] = $this->decide($hook, $event);

        if ($outcome === HookDelivery::OUTCOME_QUEUED && $this->deployAlreadyRunning($hook)) {
            $outcome = HookDelivery::OUTCOME_COALESCED;
            $reason = 'a deploy is already running for this project';
        }

        $delivery = $this->recordOnce($hook, $provider, $request, $event, $outcome, $reason, $change);
        if ($delivery === null) {
            return $this->duplicate($event);
        }

        $this->startWork($hook, $delivery, $outcome);

        $body = ['outcome' => $outcome];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return new HookResponse($this->statusFor($event), $body);
    }

    /**
     * A push for the tracked branch waits for a deploy already running on the project (from any
     * source) rather than racing it; Coalescing picks it up once that deploy finishes.
     */
    private function deployAlreadyRunning(DeployHook $hook): bool
    {
        return $hook->user !== null && Coalescing::isRunning($hook->user);
    }

    /**
     * Records the delivery, or returns null when it was already recorded. The delivery id is not
     * signed, so a signed push body is acted on once too, in the same transaction as the row so a
     * failed write does not burn the body. A concurrent request for the same delivery gets the row.
     */
    private function recordOnce(
        DeployHook $hook,
        HookProvider $provider,
        Request $request,
        HookEvent $event,
        string $outcome,
        ?string $reason,
        ?RefChange $change,
    ): ?HookDelivery {
        $guardBody = $this->guardsTheBody($hook, $provider, $request, $event);
        try {
            return DB::transaction(fn () => ! $guardBody || $this->firstSeen($hook, $request)
                ? $this->record($hook, $provider->name(), $event, $outcome, $reason, $change)
                : null);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * Every signed push is remembered, ignored ones too: whether a push deploys
     * depends on the branch tracked when it arrives, which can change before a replay.
     */
    private function guardsTheBody(DeployHook $hook, HookProvider $provider, Request $request, HookEvent $event): bool
    {
        return $event->kind === HookEvent::PUSH && $provider->signsTheBody($request, (string) $hook->secret());
    }

    private function startWork(DeployHook $hook, HookDelivery $delivery, string $outcome): void
    {
        if ($outcome === HookDelivery::OUTCOME_QUEUED) {
            RunHookDelivery::dispatch($delivery->id);
        } elseif ($outcome === HookDelivery::OUTCOME_COALESCED && $hook->user !== null) {
            Coalescing::defer($delivery, $hook->user);
        }
    }

    private function duplicate(HookEvent $event): HookResponse
    {
        return new HookResponse($this->statusFor($event), ['outcome' => 'duplicate']);
    }

    private function statusFor(HookEvent $event): int
    {
        return $event->kind === HookEvent::PING ? 200 : 202;
    }

    private function recognise(Request $request): ?HookProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->recognises($request)) {
                return $provider;
            }
        }

        return null;
    }

    /** Scoped to one hook, so an attack on one project never throttles another. */
    private function throttleKey(DeployHook $hook): string
    {
        return 'deploy-hook-unverified:'.$hook->id;
    }

    private function alreadyRecorded(DeployHook $hook, string $deliveryId): bool
    {
        return HookDelivery::query()
            ->where('deploy_hook_id', $hook->id)
            ->where('delivery_id', $deliveryId)
            ->exists();
    }

    private function firstSeen(DeployHook $hook, Request $request): bool
    {
        $this->forgetOldDigests($hook);

        return DB::table('hook_payload_digests')->insertOrIgnore([
            'deploy_hook_id' => $hook->id,
            'digest' => hash('sha256', $request->getContent()),
            'created_at' => now(),
        ]) === 1;
    }

    private function forgetOldDigests(DeployHook $hook): void
    {
        DB::table('hook_payload_digests')
            ->where('deploy_hook_id', $hook->id)
            ->where('created_at', '<', now()->subDays(self::DIGEST_RETENTION_DAYS))
            ->delete();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?RefChange} outcome, why when
     *                                                     ignored, and the change it rests on: the one that deploys or, for
     *                                                     an ignored push, the first, so the row still names a ref
     */
    private function decide(DeployHook $hook, HookEvent $event): array
    {
        if ($event->kind === HookEvent::PING) {
            return [HookDelivery::OUTCOME_IGNORED, 'ping', null];
        }

        if ($event->kind !== HookEvent::PUSH) {
            return [HookDelivery::OUTCOME_IGNORED, 'unsupported event: '.$event->event, null];
        }

        if ($event->changes === []) {
            return [HookDelivery::OUTCOME_IGNORED, 'the push changed no refs', null];
        }

        return $this->decidePush($event, $this->trackedBranch($hook));
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?RefChange}
     */
    private function decidePush(HookEvent $event, ?string $tracked): array
    {
        $reasons = [];
        foreach ($event->changes as $change) {
            $why = $this->whyNotDeployed($change, $tracked);
            if ($why === null) {
                return [HookDelivery::OUTCOME_QUEUED, null, $change];
            }

            $reasons[] = $why;
        }

        $reason = count($reasons) === 1
            ? $reasons[0]
            : 'none of '.count($reasons).' changes deploys: '.implode('; ', $reasons);

        return [HookDelivery::OUTCOME_IGNORED, $reason, $event->changes[0]];
    }

    /**
     * Why one ref change is no reason to deploy, or null when it is.
     */
    private function whyNotDeployed(RefChange $change, ?string $tracked): ?string
    {
        if ($change->tag) {
            return 'tag push';
        }

        if ($change->branch === null) {
            return 'not a branch push: '.$change->ref;
        }

        if ($change->deleted) {
            return 'branch deleted: '.$change->branch;
        }

        if ($tracked === null) {
            return 'the checkout is no longer connected to a branch';
        }

        if ($change->branch !== $tracked) {
            return "branch {$change->branch} is not the tracked branch {$tracked}";
        }

        return null;
    }

    /**
     * Read at delivery time, not stored on the hook: the branch belongs to the
     * checkout, so a change of branch is followed rather than copied.
     */
    private function trackedBranch(DeployHook $hook): ?string
    {
        $user = $hook->user;
        if ($user === null) {
            return null;
        }

        $branch = $user->getSiteGit($hook->path_key)['branch'] ?? '';

        return $branch !== '' ? $branch : null;
    }

    /**
     * `event` and `delivery_id` are only written for a delivery that proved who sent it. Older
     * deliveries are pruned here, where a row was just added, so the history stays a bounded window.
     */
    private function record(DeployHook $hook, string $provider, ?HookEvent $event, string $outcome, ?string $reason, ?RefChange $change = null): HookDelivery
    {
        /** @var HookDelivery */
        $delivery = HookDelivery::create([
            'deploy_hook_id' => $hook->id,
            'provider' => $provider,
            'delivery_id' => $event?->deliveryId,
            'event' => $event !== null ? substr($event->event, 0, 64) : null,
            'branch' => $change?->branch !== null ? substr($change->branch, 0, 255) : null,
            'commit' => $change?->commit,
            'outcome' => $outcome,
            'reason' => $reason !== null ? substr($reason, 0, 255) : null,
        ]);

        HookDelivery::pruneOldest($hook->id);

        return $delivery;
    }
}
