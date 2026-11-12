<?php

namespace App\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Project as UserProject;
use App\System\Project\Git\Path as GitPath;
use App\System\Project\Git\WorkTree;

/**
 * The panel API's view of a project's git checkout, done by {@see WorkTree}: this picks the work tree and
 * runs commands through the DinD shell when there is one, otherwise as the account on the host.
 */
class Git extends WorkTree
{
    protected UserProject $project;

    /** Without a path, the DinD app directory, or what the main domain serves: the rule WP-CLI uses. */
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
                $main = $project->model()->getMainDomain();
                $this->absolutePath = $home.($main?->getDocumentRoot() ?? '/public_html');
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

        return $this->project->system()->exec(Privileged::account()->gitOnHost($this->user()->username, $cmd));
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        $runtime = $this->project->runtime();
        if ($runtime instanceof Dind) {
            return $runtime->shell()->execAsUserQuiet($cmd, [], $timeout);
        }

        return $this->runCommand($cmd, $timeout);
    }

    protected function runsOnHost(): bool
    {
        return ! $this->project->runsInDind();
    }
}
