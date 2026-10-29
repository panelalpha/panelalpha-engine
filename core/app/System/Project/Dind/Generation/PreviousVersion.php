<?php

namespace App\System\Project\Dind\Generation;

use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind as DindProject;

/**
 * The version that served before a redeploy, started again once its containers were replaced: from the
 * checkout kept in ~/.project-prev, the `panelalpha-serving` image tags and the routing snapshot's details.
 */
final class PreviousVersion
{
    private const COMPOSE_TIMEOUT_SECONDS = 600;

    /** How long it gets to answer again: it answered before the redeploy. */
    private const ANSWER_SECONDS = 180;

    public function __construct(private readonly DindProject $project) {}

    public function restorable(): bool
    {
        return (new RoutingSnapshot($this->project))->entry() !== null
            && (new CheckoutAside($this->project))->restorable()
            && (new ServingImages($this->project))->stillHeld();
    }

    /**
     * Null once it answers on its port again, else why not. A port never recorded is not probed,
     * and so never reported as answering.
     */
    public function start(): ?string
    {
        $binds = CheckoutAside::bindsMovedAside($this->project->username());
        if (! (new CheckoutAside($this->project))->bringBack()) {
            return 'its checkout could not be put back';
        }
        $port = (new RoutingSnapshot($this->project))->restoreDetails();
        if ((new ServingImages($this->project))->restoreNames() === null) {
            return 'its images could not be named again';
        }

        $failure = $this->composeUp($binds);
        if ($failure !== null) {
            return $failure;
        }
        if ($port === null) {
            return 'its port was not recorded, so it was not checked';
        }

        return ZeroDowntimeRedeploy::answersWithin($this->project, $port, self::ANSWER_SECONDS);
    }

    /**
     * Null once its containers are up. Recreated when the new version's containers bind-mount
     * the tree that was just moved away.
     */
    private function composeUp(bool $recreate): ?string
    {
        $up = ['up', '-d', '--remove-orphans', '--no-build'];
        if ($recreate) {
            $up[] = '--force-recreate';
        }
        try {
            $this->project->shell()->execAsUserQuiet($this->project->userAppComposeCommand($up), [], self::COMPOSE_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            return 'it did not start: '.AppHealth::trimReason($e->getMessage());
        }

        return null;
    }
}
