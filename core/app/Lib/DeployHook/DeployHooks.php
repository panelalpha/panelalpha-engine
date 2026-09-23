<?php

namespace App\Lib\DeployHook;

use App\Models\DeployHook;
use App\Models\User;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\Exception as GitException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creating a Deploy Hook for a checkout.
 */
class DeployHooks
{
    /**
     * Said on create for a Deploy-managed checkout, because a push is not a
     * merge there: the checkout is made to match the repository whatever state
     * it was in.
     */
    public const FORCE_PULL_WARNING = 'Every push to the tracked branch force-updates the checkout to match the repository: '
        . 'local changes to tracked files and untracked files that are not engine-managed are discarded '
        . 'before the rebuild.';

    /**
     * The hook for this checkout, made if it does not exist yet.
     *
     * Idempotent on purpose: an assistant that is unsure whether it already
     * set a hook up can call this again without invalidating the URL it
     * already registered -- and without learning the secret a second time.
     *
     * @throws GitException 422 when the checkout is not connected to git
     */
    public function create(User $user, ProjectGit $git): HookCreation
    {
        // Deploy-managed or Site Git alike: what a push does to each is the
        // delivery's business (see GitCheckoutSync), not a reason to refuse one.
        if (!$git->isConnected()) {
            throw new GitException('The checkout is not connected to git, so there is nothing for a push to deploy.', 422);
        }

        $pathKey = $git->pathKey();
        $existing = $this->find($user, $pathKey);
        if ($existing !== null) {
            return new HookCreation($existing, null, false);
        }

        $secret = DeployHook::newSecret();
        $hook = new DeployHook([
            'user_id' => $user->id,
            'path_key' => $pathKey,
            'public_id' => DeployHook::newPublicId(),
        ]);
        $hook->setSecret($secret);
        $hook->registered_url = $hook->url();

        try {
            $hook->save();
        } catch (UniqueConstraintViolationException) {
            // A concurrent create won the (user, checkout) slot. Whoever it
            // was holds the secret; this call is the "asked again" case.
            $winner = $this->find($user, $pathKey);
            if ($winner === null) {
                throw new GitException('Could not create the deploy hook.', 500);
            }

            return new HookCreation($winner, null, false);
        }

        return new HookCreation($hook, $secret, true);
    }

    /**
     * What a client should be told about the state a push leaves the checkout
     * in, or null when there is nothing to warn about: a Site Git checkout is
     * only ever fast-forwarded, so it loses nothing to a push.
     */
    public function warningFor(ProjectGit $git): ?string
    {
        return $git->isDeployManaged() ? self::FORCE_PULL_WARNING : null;
    }

    /**
     * The hook for this checkout, or null. Deliberately does not ask whether
     * the checkout is still connected to git: a hook that outlived its
     * checkout is exactly the one a client needs to be able to see and delete.
     */
    public function show(User $user, ProjectGit $git): ?DeployHook
    {
        $hook = $this->find($user, $git->pathKey());
        if ($hook !== null) {
            // So a delivery whose deploy was killed reads as failed when the
            // client looks, rather than only once another push comes in.
            InterruptedDeliveries::settle($hook, $user->username);
        }

        return $hook;
    }

    /**
     * Replace the hook's URL and secret. The old URL stops answering at once,
     * because the lookup is by the opaque id; the delivery history stays,
     * since it is what happened, not what was configured.
     *
     * @return ?HookRotation null when the checkout has no hook to rotate
     */
    public function rotate(User $user, ProjectGit $git): ?HookRotation
    {
        $hook = $this->show($user, $git);
        if ($hook === null) {
            return null;
        }

        $secret = DeployHook::newSecret();
        $hook->public_id = DeployHook::newPublicId();
        $hook->setSecret($secret);
        $hook->registered_url = $hook->url();
        $hook->save();

        return new HookRotation($hook, $secret);
    }

    /**
     * Remove the hook and, with it, its deliveries. Its URL is a 404 from now on.
     *
     * @return bool false when the checkout had no hook
     */
    public function delete(User $user, ProjectGit $git): bool
    {
        $hook = $this->show($user, $git);
        if ($hook === null) {
            return false;
        }

        $hook->delete();

        return true;
    }

    /**
     * Disconnect a checkout from git and, with it, forget its Deploy Hook:
     * once nothing tracks a branch, a push has nothing to redeploy, and the
     * URL is one less credential a leak could still act on.
     *
     * @return array<string, mixed> the disconnected status, unchanged
     * @throws GitException whatever $git->disconnect() throws -- the hook is
     *         only forgotten once the disconnect itself has succeeded
     */
    public function disconnectAndForget(User $user, ProjectGit $git): array
    {
        $status = $git->disconnect();
        $this->delete($user, $git);

        return $status;
    }

    private function find(User $user, string $pathKey): ?DeployHook
    {
        /** @var ?DeployHook */
        return DeployHook::query()
            ->where('user_id', $user->id)
            ->where('path_key', $pathKey)
            ->first();
    }
}
