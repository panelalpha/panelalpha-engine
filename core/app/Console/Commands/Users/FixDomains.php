<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\User;
use App\System;

class FixDomains extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:fix-domains', 'users:fix-domains'];

    protected $signature = 'project:domain:fix' . ProjectOptions::SIGNATURE;

    protected $description = 'Create missing document-root directories for a project\'s domains';

    protected function progress(User $user): string
    {
        return "Fixing domains for user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        foreach ($user->domains as $domain) {
            $domain->projectDomain()->createDomainRootDir();
        }
    }

    protected function finished(User $user): string
    {
        return '  Finished fixing domains for the user.';
    }

    protected function afterAll(): void
    {
        (new System())->webserver()->reload();
    }
}
