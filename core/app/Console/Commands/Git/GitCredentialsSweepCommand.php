<?php

namespace App\Console\Commands\Git;

use App\System;
use App\System\Project\Git\CredentialFiles;
use Illuminate\Console\Command;

/** Reaches the credential files a project that never runs git again would keep. */
class GitCredentialsSweepCommand extends Command
{
    protected $signature = 'git:credentials:sweep';

    protected $description = 'Remove stale git credential files left in account homes';

    public function handle(): int
    {
        ['homes' => $homes, 'removed' => $removed] = CredentialFiles::sweepHomes(app(System::class));

        $this->info("Swept {$removed} stale git credential file(s) from {$homes} home(s).");

        return self::SUCCESS;
    }
}
