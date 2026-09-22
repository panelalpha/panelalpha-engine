<?php

namespace App\Lib\DeployHook;

use App\Models\User;
use App\System\Project\Git\FastForwardRefused;

/**
 * Brings a project's checkout up to what the repository now holds and, for a
 * Deploy-managed checkout, rebuilds the running app from it.
 *
 * An interface so that what a delivery does with the outcome -- which is the
 * logic worth testing -- does not need a Docker daemon to run under test.
 */
interface CheckoutSync
{
    /**
     * @param ?string $pushedCommit the commit the push named, used only when the checkout cannot say
     * @param ?callable(string): void $onDeployStarted called with the deploy id as soon as the deploy
     *        this sync runs under has started -- before the pull, so a deploy killed mid-way can
     *        still be traced back to its delivery. Never called for a Site Git checkout.
     * @throws FastForwardRefused a Site Git checkout that git would not fast-forward; nothing was changed
     * @throws \Throwable the pull or the rebuild failed; the message is the reason
     */
    public function pullAndRebuild(User $user, string $pathKey, ?string $pushedCommit, ?callable $onDeployStarted = null): void;
}
