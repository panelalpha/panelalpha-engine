<?php

namespace App\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project as UserProject;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\WorkTree;

/**
 * The panel API's view of a project's git checkout. Everything it can do lives
 * in {@see WorkTree}; this decides which directory is the work tree and how a
 * command reaches the account -- through the DinD shell when there is one,
 * otherwise `sudo -u` on the host.
 */
class Git extends WorkTree
{
    protected UserProject $project;

    public function __construct(UserProject $project, ?string $path = null)
    {
        $this->project = $project;
        $home = rtrim($project->homeDirPath(), '/');
        $trimmed = trim((string) $path);

        if ($trimmed === '') {
            $runtime = $project->runtime();
            if ($runtime instanceof Dind) {
                $this->absolutePath = $runtime->userAppDirPath();
            } else {
                $this->absolutePath = $home . '/public_html';
            }
        } else {
            $this->absolutePath = $project->resolvePath($trimmed);
        }

        $this->pathKey = GitPath::key($this->absolutePath, $home);
    }

    protected function user(): ModelsUser
    {
        return $this->project->model();
    }

    protected function system(): EngineSystem
    {
        return $this->project->system();
    }

    protected function homeDirPath(): string
    {
        return $this->project->homeDirPath();
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        $runtime = $this->project->runtime();
        if ($runtime instanceof Dind) {
            return $runtime->shell()->execAsUser($cmd, [], $timeout);
        }

        return $this->project->system()->execOnHost(['sudo', '-u', $this->user()->username, ...$cmd]);
    }
}
