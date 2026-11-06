<?php

namespace App\Lib\DeployHook;

use App\Exceptions\DeployAlreadyRunningException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Findings\Finding;
use App\Models\DeployHook;
use App\Models\HookDelivery;
use App\Models\User;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\FastForwardRefused;
use Throwable;

/**
 * The queued half of a delivery: the pull (and rebuild), then how it went. Never lets a failure
 * escape: a broken build is `result = deploy_failed`, not a job that retries into the same failure.
 */
class DeliveryRunner
{
    public function __construct(private readonly CheckoutSync $sync) {}

    /** Settles earlier deliveries whose deploy was killed first, so none stays `queued` with no result. */
    public function run(HookDelivery $delivery): void
    {
        $hook = $delivery->hook;
        $user = $hook?->user;
        if ($hook === null || $user === null) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, 'The project this hook belonged to no longer exists.');

            return;
        }

        InterruptedDeliveries::settle($hook, $user->username, $delivery->id);

        $before = self::currentDeployId($user->username);

        if (! $this->synced($delivery, $hook, $user, $before)) {
            return;
        }

        $deployId = ($delivery->deploy_id ?? self::startedDeployId($before, $user->username));
        [$result, $detail] = self::resultOf($deployId, $user->username);
        $this->finish($delivery, $result, $detail, $deployId);
    }

    /**
     * False when the pull or rebuild ended the delivery: a refused fast-forward is not a failure and
     * started no deploy; a deploy already running defers this delivery until that one finishes.
     */
    private function synced(HookDelivery $delivery, DeployHook $hook, User $user, ?string $before): bool
    {
        $onDeployStarted = self::deployIdRecorder($delivery);

        try {
            $this->sync->pullAndRebuild($user, $hook->path_key, $delivery->commit, $onDeployStarted);
        } catch (FastForwardRefused $e) {
            $this->finish($delivery, HookDelivery::RESULT_PULL_REFUSED, self::scrub($e->getMessage()));

            return false;
        } catch (DeployAlreadyRunningException) {
            Coalescing::defer($delivery, $user);

            return false;
        } catch (GitException $e) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, 'git pull failed: '.self::scrub($e->getMessage()), ($delivery->deploy_id ?? self::startedDeployId($before, $user->username)));

            return false;
        } catch (Throwable $e) {
            $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, self::scrub($e->getMessage()), ($delivery->deploy_id ?? self::startedDeployId($before, $user->username)));

            return false;
        }

        return true;
    }

    /** Written the moment the deploy starts, so a deploy killed half-way stays traceable to this delivery. */
    private static function deployIdRecorder(HookDelivery $delivery): \Closure
    {
        return static function (string $deployId) use ($delivery): void {
            $delivery->deploy_id = $deployId;
            $delivery->save();
        };
    }

    /**
     * What the deploy this push started actually ended as: a `partial` deploy must not read as `deployed`.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function resultOf(?string $deployId, string $username): array
    {
        $latest = $deployId !== null ? DeployLogger::readLatestFor($username) : null;
        if ($latest === null || ($latest['id'] ?? null) !== $deployId) {
            return [HookDelivery::RESULT_DEPLOYED, null];
        }

        return match ($latest['status'] ?? null) {
            DeployLogger::STATUS_PARTIAL => [HookDelivery::RESULT_PARTIAL, self::note(Finding::sentence(DeployStatus::warningsOf($latest)))],
            DeployLogger::STATUS_FAILED => [HookDelivery::RESULT_DEPLOY_FAILED, self::note(DeployStatus::errorOf($latest)) ?? 'The deploy failed.'],
            DeployLogger::STATUS_CANCELLED => [HookDelivery::RESULT_DEPLOY_FAILED, 'The deploy was cancelled.'],
            default => [HookDelivery::RESULT_DEPLOYED, null],
        };
    }

    private static function note(?string $text): ?string
    {
        return $text !== null && $text !== '' ? self::scrub($text) : null;
    }

    /** The deploy id in `latest.json`, read before and after the sync; a Site Git checkout never starts one. */
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

    /** For a job the queue gave up on: a delivery must not stay `queued` forever; a written result stays. */
    public function abandon(HookDelivery $delivery, Throwable $e): void
    {
        if ($delivery->result !== null) {
            return;
        }

        $this->finish($delivery, HookDelivery::RESULT_DEPLOY_FAILED, self::scrub($e->getMessage()));
    }

    /** Strips every `scheme://user:secret@` from a message git may have echoed a credentialed URL into. */
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
