<?php

namespace App\Lib\Deploy\Platform;

/**
 * The plan the deploy under way was asked to follow, bound as a request-lifetime singleton because
 * `$user->project()` builds a new `Dind` on every call and cannot carry it; nothing is persisted.
 */
final class DeployPlanContext
{
    private ?DeployPlan $plan = null;

    public function set(?DeployPlan $plan): void
    {
        $this->plan = $plan;
    }

    public function get(): ?DeployPlan
    {
        return $this->plan;
    }

    public function clear(): void
    {
        $this->plan = null;
    }
}
