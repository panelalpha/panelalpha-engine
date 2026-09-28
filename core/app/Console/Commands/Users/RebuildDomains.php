<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;

class RebuildDomains extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:rebuild-domains', 'users:rebuild-domains'];

    protected $signature = 'project:domain:rebuild' . ProjectOptions::SIGNATURE;

    protected $description = 'Recreate the webserver configuration for a project\'s domains';

    protected function progress(User $user): string
    {
        return "Rebuilding domains for user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        foreach ($user->domains as $domain) {
            $this->info("  Rebuilding domain '{$domain->domain}'...");
            $domain->projectDomain()->rebuild();
        }
        $user->project()->reloadWebserver();
    }

    protected function afterAll(): void
    {
        (new System())->webserver()->reload();
    }
}
