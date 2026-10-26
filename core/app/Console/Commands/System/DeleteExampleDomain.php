<?php

namespace App\Console\Commands\System;

use App\Models\Domain;
use App\Models\Setting;
use Illuminate\Console\Command;

class DeleteExampleDomain extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['system:delete-example-domain'];

    protected $signature = 'system:example:delete {--force : Skip confirmation prompts}';

    protected $description = 'Delete example domain and user if they exist.';

    public function handle(): int
    {
        $alreadyCreated = ! empty(Setting::get('example-domain-created'));
        if (! $alreadyCreated) {
            $this->info('Example domain has not been created or has already been deleted.');

            return 0;
        }

        // Find the example domain by the flag we set during creation
        $domain = Domain::findExampleDomain();
        if (! $domain) {
            $this->warn('Could not find example domain to delete.');
            Setting::set('example-domain-created', '');

            return 0;
        }

        $user = $domain->user;
        if (! $user) {
            $this->warn('Example domain found but associated user not found.');
            Setting::set('example-domain-created', '');

            return 0;
        }

        $this->info("Found example domain: {$domain->domain} (user: {$user->username})");

        // Use the users:delete command to properly clean up everything
        $shouldDelete = $this->option('force') || $this->confirm("This will completely delete the example user '{$user->username}' and all associated data. Continue?");

        if ($shouldDelete) {
            $commandArgs = ['username' => [$user->username]];
            if ($this->option('force')) {
                $commandArgs['--force'] = true;
            }

            $exitCode = $this->call('users:delete', $commandArgs);

            if ($exitCode === 0) {
                // Reset the setting only after successful deletion
                Setting::set('example-domain-created', '');
                $this->info('Example domain and user deleted successfully.');

                return 0;
            } else {
                $this->error('Failed to delete example user.');

                return 1;
            }
        } else {
            $this->info('Deletion cancelled.');

            return 0;
        }
    }
}
