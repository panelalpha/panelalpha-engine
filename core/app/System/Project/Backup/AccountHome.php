<?php

namespace App\System\Project\Backup;

use App\Support\JobDeadline;
use App\System\Privileged;
use App\System\Project as UserProject;

/** Root work on exact paths under the account's home. */
abstract class AccountHome
{
    public function __construct(
        protected UserProject $project,
        protected JobDeadline $deadline,
    ) {}

    public function path(string $relative): string
    {
        return $this->home().'/'.ltrim($relative, '/');
    }

    protected function home(): string
    {
        return rtrim($this->project->homeDirPath(), '/');
    }

    protected function existsOnHost(string $path): bool
    {
        return $this->project->system()->runProcess(Privileged::files()->exists($path))->getExitCode() === 0;
    }
}
