<?php

namespace App\Lib\DeployHook;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\HookDelivery;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\FastForwardRefused;
use Throwable;

/**
 * The queued half of a delivery: does the pull (and, for a Deploy-managed
 * checkout, the rebuild), then writes down how it went.
 *
 * It never lets a failure escape. A push whose build breaks is handled the way
 * a manual pull is today -- the checkout keeps the new commit, the app is what
 * the failed deploy left, and there is no rollback -- and the way the client
 * learns of it is `result = deploy_failed` on the delivery, not a job that
 * retries into the same failure.
 */
class DeliveryRunner
{
    public function __construct(private readonly CheckoutSync $sync)
    {
    }

    public function run(HookDelivery $delivery): void
    {
        $hook = $delivery->hook;
        $user = $hook?->user;
        if ($hook === null || $user === null) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, 'The project this hook belonged to no longer exists.');

            return;
        }

        // An earlier delivery whose deploy was killed would otherwise stay
        // `queued` with no result until the queue gives up on its job.
        InterruptedDeliveries::settle($hook, $user->username, $delivery->id);

        $before = self::currentDeployId($user->username);

        // Written the moment the deploy starts, not when it ends: a deploy
        // killed half-way must still be traceable to this delivery, which is
        // how InterruptedDeliveries later tells it was interrupted.
        $onDeployStarted = static function (string $deployId) use ($delivery): void {
            $delivery->deploy_id = $deployId;
            $delivery->save();
        };

        try {
            $this->sync->pullAndRebuild($user, $hook->path_key, $delivery->commit, $onDeployStarted);
        } catch (FastForwardRefused $e) {
            // Not a failure of the pull: git looked and said no, before it
            // touched anything. The client has to fix the checkout or the
            // history, so the row says that rather than "failed". No deploy
            // ever started, so there is nothing to point at.
            $this->finish($delivery, HookDelivery::RESULT_PULL_REFUSED, self::scrub($e->getMessage()));

            return;
        } catch (DeployAlreadyRunningException) {
            // A deploy started between this job being queued and it running
            // -- another push, a manual rebuild, the same race coalescing
            // exists to avoid, just lost at the wire instead of at arrival.
            // Not a failure: this delivery becomes the one Coalescing::
            // runPendingFor() runs once that other deploy's DeployLogger::
            // finish() fires, the same as a delivery coalesced up front --
            // or now, if that deploy already finished while this was caught.
            Coalescing::defer($delivery, $user);

            return;
        } catch (GitException $e) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, 'git pull failed: ' . self::scrub($e->getMessage()), ($delivery->deploy_id ?? self::startedDeployId($before, $user->username)));

            return;
        } catch (Throwable $e) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, self::scrub($e->getMessage()), ($delivery->deploy_id ?? self::startedDeployId($before, $user->username)));

            return;
        }

        $deployId = ($delivery->deploy_id ?? self::startedDeployId($before, $user->username));
        [$result, $detail] = self::resultOf($deployId, $user->username);
        $this->finish($delivery, $result, $detail, $deployId);
    }

    /**
     * What the deploy this push started actually ended as. A rebuild returns
     * normally for a `partial` deploy -- the app started but does not answer,
     * or answers 5xx -- and that must not read as a clean `deployed`.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function resultOf(?string $deployId, string $username): array
    {
        $latest = $deployId !== null ? DeployLogger::readLatestFor($username) : null;
        if ($latest === null || ($latest['id'] ?? null) !== $deployId) {
            return [HookDelivery::RESULT_DEPLOYED, null];
        }

        $error = is_string($latest['error'] ?? null) && $latest['error'] !== '' ? self::scrub($latest['error']) : null;

        return match ($latest['status'] ?? null) {
            DeployLogger::STATUS_PARTIAL => [HookDelivery::RESULT_PARTIAL, $error],
            DeployLogger::STATUS_FAILED => [HookDelivery::RESULT_DEPLOY_FAILED, $error ?? 'The deploy failed.'],
            DeployLogger::STATUS_CANCELLED => [HookDelivery::RESULT_DEPLOY_FAILED, 'The deploy was cancelled.'],
            default => [HookDelivery::RESULT_DEPLOYED, null],
        };
    }

    /**
     * The deploy id this push's rebuild is running under, or null for a Site
     * Git checkout: that is only ever fast-forwarded, so it never starts one.
     * Read from `latest.json` before and after the sync rather than asked of
     * the checkout, so this needs no opinion on which kind it was.
     */
    private static function currentDeployId(string $username): ?string
    {
        $id = DeployLogger::readLatestFor($username)['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function startedDeployId(?string $before, string $username): ?string
    {
        $after = self::currentDeployId($username);

        return $after !== null && $after !== $before ? $after : null;
    }

    /**
     * For a job the queue gave up on (killed, timed out): a delivery must not
     * stay `queued` forever because its worker died. A result already written
     * is the truth and stays.
     */
    public function abandon(HookDelivery $delivery, Throwable $e): void
    {
        if ($delivery->result !== null) {
            return;
        }

        $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, self::scrub($e->getMessage()));
    }

    /**
     * A failure message is stored and, later, shown to the client, and git
     * echoes the URL it was working on -- credentials included if one was
     * ever embedded in it. `GitUrl::sanitize()` takes a URL, not a sentence
     * with one in it, so every `scheme://user:secret@` in the text is
     * stripped here.
     */
    private static function scrub(string $message): string
    {
        return (string) preg_replace('#(\b[a-z][a-z0-9+.-]*://)[^/@\s]+@#i', '$1', $message);
    }

    private function finish(HookDelivery $delivery, string $result, ?string $detail = null, ?string $deployId = null): void
    {
        $delivery->result = $result;
        $delivery->detail = $detail !== null ? mb_substr($detail, 0, 2000) : null;
        $delivery->deploy_id = $deployId;
        $delivery->save();
    }
}
