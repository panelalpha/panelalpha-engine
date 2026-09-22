<?php

namespace App\Lib\DeployHook;

use App\Jobs\RunHookDelivery;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
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
 *  4. Have we already acted on this delivery? A redelivery is a no-op.
 *  5. What does it say, and is it for the branch this checkout tracks? A push
 *     can name several refs (Bitbucket sends them in one delivery); it deploys,
 *     once, if any of them is the tracked branch moving forward.
 */
class HookReceiver
{
    /** Unverified requests (no recognised provider, or a bad signature) a single hook tolerates in a minute before 429. */
    private const int MAX_UNVERIFIED_REQUESTS_PER_MINUTE = 30;

    /** @var list<HookProvider> */
    private array $providers;

    /**
     * @param list<HookProvider>|null $providers
     */
    public function __construct(?array $providers = null)
    {
        $this->providers = $providers ?? [
            new GithubProvider(),
            new GitlabProvider(),
            new BitbucketCloudProvider(),
            new BitbucketDataCenterProvider(),
        ];
    }

    public function receive(DeployHook $hook, Request $request): HookResponse
    {
        $throttleKey = $this->throttleKey($hook);
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_UNVERIFIED_REQUESTS_PER_MINUTE)) {
            // Not recorded as a delivery: this is the flood itself, not a
            // request worth a row, and recording it would let the flood fill
            // the table it is already trying to burn CPU against.
            return new HookResponse(429, ['message' => 'Too many failed signatures for this hook.']);
        }

        $provider = $this->recognise($request);
        if ($provider === null) {
            // Costs the sender nothing (no headers to fake, no HMAC to
            // compute) and would otherwise write a row on every attempt, so
            // it counts against the same throttle as a bad signature.
            RateLimiter::hit($throttleKey, 60);
            $this->record($hook, 'unknown', null, HookDelivery::OUTCOME_REJECTED, 'unsupported provider');

            return new HookResponse(400, ['message' => 'Unsupported provider.']);
        }

        $secret = $hook->secret();
        if ($secret === null || !$provider->verify($request, $secret)) {
            RateLimiter::hit($throttleKey, 60);
            $this->record($hook, $provider->name(), null, HookDelivery::OUTCOME_REJECTED, 'missing or invalid signature');

            return new HookResponse(401, ['message' => 'Invalid signature.']);
        }

        try {
            $event = $provider->parse($request);
        } catch (InvalidPayload $e) {
            $this->record($hook, $provider->name(), null, HookDelivery::OUTCOME_REJECTED, 'invalid payload');

            return new HookResponse(400, ['message' => 'Invalid payload.']);
        }

        if ($event->deliveryId !== null && $this->alreadyRecorded($hook, $event->deliveryId)) {
            return new HookResponse($event->kind === HookEvent::PING ? 200 : 202, ['outcome' => 'duplicate']);
        }

        [$outcome, $reason, $change] = $this->decide($hook, $event);

        if ($outcome === HookDelivery::OUTCOME_QUEUED && $hook->user !== null && Coalescing::isRunning($hook->user)) {
            // The tracked branch would deploy, but this project already has a
            // deploy running -- from this hook, another one, a manual
            // rebuild, or the account's own creation. It waits rather than
            // races: Coalescing::runPendingFor() picks it up once that
            // deploy finishes, wherever DeployLogger::finish() is called --
            // or Coalescing::defer() runs it itself, if that deploy finished
            // before the mark went down.
            $outcome = HookDelivery::OUTCOME_COALESCED;
            $reason = 'a deploy is already running for this project';
        }

        try {
            $delivery = $this->record($hook, $provider->name(), $event, $outcome, $reason, $change);
        } catch (UniqueConstraintViolationException) {
            // The same delivery, twice, at once: the other request got the
            // row and is the one that queues the work.
            return new HookResponse($event->kind === HookEvent::PING ? 200 : 202, ['outcome' => 'duplicate']);
        }

        if ($outcome === HookDelivery::OUTCOME_QUEUED) {
            RunHookDelivery::dispatch($delivery->id);
        } elseif ($outcome === HookDelivery::OUTCOME_COALESCED && $hook->user !== null) {
            Coalescing::defer($delivery, $hook->user);
        }

        $body = ['outcome' => $outcome];
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        return new HookResponse($event->kind === HookEvent::PING ? 200 : 202, $body);
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
        return 'deploy-hook-unverified:' . $hook->id;
    }

    private function alreadyRecorded(DeployHook $hook, string $deliveryId): bool
    {
        return HookDelivery::query()
            ->where('deploy_hook_id', $hook->id)
            ->where('delivery_id', $deliveryId)
            ->exists();
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?RefChange} outcome, why when
     *         ignored, and the change it rests on: the one that deploys or, for
     *         an ignored push, the first, so the row still names a ref
     */
    private function decide(DeployHook $hook, HookEvent $event): array
    {
        if ($event->kind === HookEvent::PING) {
            return [HookDelivery::OUTCOME_IGNORED, 'ping', null];
        }

        if ($event->kind !== HookEvent::PUSH) {
            return [HookDelivery::OUTCOME_IGNORED, 'unsupported event: ' . $event->event, null];
        }

        if ($event->changes === []) {
            return [HookDelivery::OUTCOME_IGNORED, 'the push changed no refs', null];
        }

        $tracked = $this->trackedBranch($hook);
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
            : 'none of ' . count($reasons) . ' changes deploys: ' . implode('; ', $reasons);

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
            return 'not a branch push: ' . $change->ref;
        }

        if ($change->deleted) {
            return 'branch deleted: ' . $change->branch;
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

    private function record(DeployHook $hook, string $provider, ?HookEvent $event, string $outcome, ?string $reason, ?RefChange $change = null): HookDelivery
    {
        // `event` (like `delivery_id`) is only written for a delivery that
        // proved who sent it. A rejected request's headers are whatever the
        // sender typed.
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

        // A hook's history is a bounded window, not an archive: keep the
        // newest accepted and rejected deliveries, each in a window of its
        // own, and let older ones go, right here
        // where a row was just added rather than on a schedule that could
        // fall behind.
        HookDelivery::pruneOldest($hook->id);

        return $delivery;
    }
}
