<?php

namespace App\System\Project\Dind\Generation;

use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\AppHealth;

/**
 * The version that served before a redeploy, started again after the deploy
 * died with its containers already replaced: from the checkout kept in
 * ~/.project-prev, the images held by their `panelalpha-serving` tags and the
 * details the routing snapshot noted. All three live until the redeploy is
 * settled.
 */
final class PreviousVersion
{
    private const COMPOSE_TIMEOUT_SECONDS = 600;

    /** How long it gets to answer again: it answered before the redeploy. */
    private const ANSWER_SECONDS = 180;

    public function __construct(private readonly DindProject $project)
    {
    }

    public function restorable(): bool
    {
        return (new RoutingSnapshot($this->project))->entry() !== null
            && (new CheckoutAside($this->project))->restorable()
            && (new ServingImages($this->project))->stillHeld();
    }

    /** Null once it answers on its port again, else why not. */
    public function start(): ?string
    {
        $binds = CheckoutAside::bindsMovedAside($this->project->username());
        if (!(new CheckoutAside($this->project))->bringBack()) {
            return 'its checkout could not be put back';
        }
        $port = (new RoutingSnapshot($this->project))->restoreDetails();
        if ((new ServingImages($this->project))->restoreNames() === null) {
            return 'its images could not be named again';
        }

        $up = ['up', '-d', '--remove-orphans', '--no-build'];
        if ($binds) {
            // Containers of the new version read the tree that was just moved away.
            $up[] = '--force-recreate';
        }
        try {
            $this->project->shell()->execAsUserQuiet($this->project->userAppComposeCommand($up), [], self::COMPOSE_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            return 'it did not start: ' . AppHealth::trimReason($e->getMessage());
        }

        return $port === null ? null : ZeroDowntimeRedeploy::answersWithin($this->project, $port, self::ANSWER_SECONDS);
    }
}
