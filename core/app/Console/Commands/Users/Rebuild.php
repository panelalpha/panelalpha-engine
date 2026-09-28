<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;

class Rebuild extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:rebuild', 'users:rebuild'];

    protected $signature = 'project:rebuild' . ProjectOptions::SIGNATURE . ' {--wipe-vhosts-dir}';

    protected $description = 'Rebuild a project\'s docker compose stack and recreate its domain configuration files';

    private System $system;

    private string $webserver;

    protected function beforeAll(): void
    {
        $this->system = new System();
        $this->webserver = $this->system->webserver()->getCurrentWebserver();
    }

    protected function progress(User $user): string
    {
        return "Rebuilding user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        $project = $user->project();

        if ($this->option('wipe-vhosts-dir')) {
            $project->deleteAllDomainsConfigs();
        }

        $project->rebuildFromSource();

        $user->save();

        if (count($user->domains)) {
            $project->rebuildDomains();
            $project->waitForAllRunning();
            $project->reloadWebserver();
        }
    }

    protected function finished(User $user): string
    {
        return '  Finished rebuilding user.';
    }

    protected function afterAll(): void
    {
        if ($this->option('wipe-vhosts-dir')) {
            try {
                $dir = $this->system->engineDirPath() . "/webserver-config/{$this->webserver}/vhosts";
                $this->system->exec("rm -rf {$dir}/* {$dir}/.*");
            } catch (\Exception) {
            }
        }

        $this->system->webserver()->rebuildDomains();
    }
}
