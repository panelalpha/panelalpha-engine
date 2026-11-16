<?php

namespace App\Lib\DeployHook;

use App\Lib\DeployHook\Registration\CheckoutRemote;
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
     * Said on create for a Deploy-managed checkout, because a push is not a merge there: the checkout is made to match
     * the repository whatever state it was in.
     */
    public const FORCE_PULL_WARNING = 'Every push to the tracked branch force-updates the checkout to match the repository: '
        .'local changes to tracked files and untracked files that are not engine-managed are discarded '
        .'before the rebuild.';

    public function __construct(private readonly ProviderRegistrar $registrar = new ProviderRegistrar) {}

    /**
     * The hook for this checkout, made if missing; idempotent, so a caller unsure it set one up keeps its URL and does not
     * learn the secret twice. Site Git checkouts too: what a push does to each is GitCheckoutSync's business.
     *
     * @throws GitException 422 when the checkout is not connected to git
     */
    public function create(User $user, ProjectGit $git): HookCreation
    {
        if (! $git->isConnected()) {
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
            return $this->createdConcurrently($user, $pathKey);
        }

        return new HookCreation($hook, $secret, true);
    }

    /**
     * A concurrent create won the (user, checkout) slot: whoever it was holds the secret, so this is the "asked again" case.
     *
     * @throws GitException 500 when no winner can be found
     */
    private function createdConcurrently(User $user, string $pathKey): HookCreation
    {
        $winner = $this->find($user, $pathKey);
        if ($winner === null) {
            throw new GitException('Could not create the deploy hook.', 500);
        }

        return new HookCreation($winner, null, false);
    }

    /**
     * What a client should be told about the state a push leaves the checkout in, or null: a Site Git checkout is only
     * ever fast-forwarded, so it loses nothing to a push.
     */
    public function warningFor(ProjectGit $git): ?string
    {
        return $git->isDeployManaged() ? self::FORCE_PULL_WARNING : null;
    }

    /**
     * The hook for this checkout, or null, connected or not: one that outlived its checkout is the one a client must see
     * and delete. A delivery whose deploy was killed is settled here, so it reads as failed before another push comes in.
     */
    public function show(User $user, ProjectGit $git): ?DeployHook
    {
        $hook = $this->find($user, $git->pathKey());
        if ($hook !== null) {
            InterruptedDeliveries::settle($hook, $user->username);
        }

        return $hook;
    }

    /**
     * Replace the hook's URL and secret. The old URL stops answering at once, because the lookup is by the opaque id; the
     * delivery history stays, since it is what happened, not what was configured.
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
     * Disconnect a checkout from git and forget its Deploy Hook and provider webhook, so the URL is one less credential a
     * leak could act on. The remote is read first, as the disconnect forgets it.
     *
     * @return array<string, mixed> the disconnected status, unchanged
     *
     * @throws GitException whatever $git->disconnect() throws -- the hook is
     *                      only forgotten once the disconnect itself has succeeded
     */
    public function disconnectAndForget(User $user, ProjectGit $git): array
    {
        $hook = $this->find($user, $git->pathKey());
        $remote = CheckoutRemote::of($user, $git->pathKey());
        $status = $git->disconnect();
        if ($hook?->isRegistered()) {
            $this->registrar->forgetQuietly($remote, $hook);
        }
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
