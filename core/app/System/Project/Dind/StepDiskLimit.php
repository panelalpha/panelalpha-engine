<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\System;
use App\System\Project\Dind as DindProject;

/**
 * Disk checked while a deploy step runs, not only before it starts: one pull
 * took a host from 34 GB free to 0. The account's inner Docker
 * store lands on the host as uid 0, so user quota never counts it.
 */
final class StepDiskLimit
{
    public function __construct(
        private readonly System $system,
        private readonly ?int $hostMinimumBytes,
        private readonly string $homeDir,
        private readonly ?int $limitMb,
    ) {
    }

    /** Null when neither limit applies, so the step is not probed at all. */
    public static function for(DindProject $project): ?self
    {
        $minimum = HostDiskGuard::minimumFrom(config('deploy.host_min_free'));
        $limit = $project->userModel()->getDiskSpaceLimit();
        if ($minimum === null && ($limit === null || $limit <= 0)) {
            return null;
        }

        return new self($project->system(), $minimum, $project->homeDirPath(), $limit);
    }

    /** What was exceeded, or null to carry on. A reading that cannot be taken carries on. */
    public function reason(): ?string
    {
        if ($this->hostMinimumBytes !== null) {
            $lowest = (new HostDiskGuard($this->system, $this->hostMinimumBytes))->lowest();
            if ($lowest !== null && $lowest['free'] < $this->hostMinimumBytes) {
                return sprintf(
                    'the engine host has %s free on %s, under DEPLOY_HOST_MIN_FREE (%s)',
                    HostPrewarmPlan::formatBytes($lowest['free']),
                    $lowest['path'],
                    HostPrewarmPlan::formatBytes($this->hostMinimumBytes)
                );
            }
        }

        if ($this->limitMb !== null && $this->limitMb > 0) {
            $used = $this->usedBytes();
            $limit = $this->limitMb * 1024 * 1024;
            if ($used !== null && $used > $limit) {
                return sprintf(
                    'the project uses %s, over its %s disk limit',
                    HostPrewarmPlan::formatBytes($used),
                    HostPrewarmPlan::formatBytes($limit)
                );
            }
        }

        return null;
    }

    /** The whole home, the inner Docker store under it included. */
    private function usedBytes(): ?int
    {
        try {
            $output = $this->system->execOnHost(['du', '-sxk', $this->homeDir]);
        } catch (\Throwable) {
            return null;
        }
        $kb = preg_split('/\s+/', trim($output))[0] ?? '';

        return ctype_digit($kb) ? (int) $kb * 1024 : null;
    }
}
