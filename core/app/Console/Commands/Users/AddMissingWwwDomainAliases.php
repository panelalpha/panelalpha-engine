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
        $taken = [];
        foreach ($user->domains as $domain) {
            $this->info("  Checking domain {$domain->domain}...");
            $skip = $this->reasonToSkip($domain);
            if ($skip !== null) {
                $this->comment('    ' . $skip);
                continue;
            }

            // Refused as PUT .../domains/{domain} refuses it; the other domains still get theirs.
            $holder = $this->takenBy($domain);
            if ($holder !== null) {
                $taken[] = "  www.{$domain->domain} is already on this engine ({$holder}), not added.";
                continue;
            }

            $this->info('    Adding www. alias and rebuilding domain...');
            $domain->addAlias('www.' . $domain->domain);
            $domain->projectDomain()->rebuild();
            $domain->save();
            $this->info('    Domain rebuilt.');
        }

        if ($taken !== []) {
            throw new \RuntimeException(implode("\n", $taken));
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

        return null;
    }

    /** What already holds this domain's www. name, as the API's alias check sees it, or null when it is free. */
    private function takenBy(Domain $domain): ?string
    {
        $alias = 'www.' . $domain->domain;
        if ($domain->isAliasAvailable($alias)) {
            return null;
        }

        $found = $domain->findOtherDomainByNameOrAlias($alias);
        if ($found === null) {
            return 'a tunnel hostname';
        }
        $owner = $found->getUser()->username;

        return $found->domain === $alias
            ? "{$found->type} domain of project {$owner}"
            : "alias of {$found->domain}, project {$owner}";
    }

    protected function afterAll(): void
    {
        (new System())->webserver()->reload();
    }
}
