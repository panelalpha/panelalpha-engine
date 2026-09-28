<?php

namespace App\Console\Commands\Users;

use App\Console\Commands\Concerns\ProjectOptions;
use App\Console\Commands\ProjectFleetCommand;
use App\Models\Domain;
use App\Models\User;
use App\System;

class AddMissingWwwDomainAliases extends ProjectFleetCommand
{
    /** Older spellings still answer, so nothing scripted against them breaks. */
    protected $aliases = ['projects:add-missing-www-domain-aliases', 'users:add-missing-www-domain-aliases'];

    protected $signature = 'project:domain:add-www-alias' . ProjectOptions::SIGNATURE;

    protected $description = 'Add missing www. aliases to a project\'s domains';

    protected function progress(User $user): string
    {
        return "Adding www. aliases for user '{$user->username}'...";
    }

    protected function applyTo(User $user): void
    {
        foreach ($user->domains as $domain) {
            $this->info("  Checking domain {$domain->domain}...");
            $skip = $this->reasonToSkip($domain);
            if ($skip !== null) {
                $this->comment('    ' . $skip);
                continue;
            }

            $this->info('    Adding www. alias and rebuilding domain...');
            $domain->addAlias('www.' . $domain->domain);
            $domain->projectDomain()->rebuild();
            $domain->save();
            $this->info('    Domain rebuilt.');
        }
    }

    /** Why this domain gets no `www.` alias, or null when it should have one. */
    private function reasonToSkip(Domain $domain): ?string
    {
        if ($domain->type === 'sub') {
            return 'It is subdomain, skipping.';
        }

        $alias = 'www.' . $domain->domain;

        if (in_array($alias, $domain->getAliases())) {
            return 'It already has www. alias, skipping.';
        }

        $found = $domain->findOtherDomainByNameOrAlias($alias);
        if (!$found) {
            return null;
        }

        $owner = $found->getUser()->username;

        return $found->domain === $alias
            ? "It already exists as {$found->type} domain under user {$owner}, skipping."
            : "It already exists as alias of {$found->domain} domain under user {$owner}, skipping.";
    }

    protected function afterAll(): void
    {
        (new System())->webserver()->reload();
    }
}
