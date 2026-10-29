<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;
use Illuminate\Console\Command;

class ShowCommand extends Command
{
    protected $signature = 'backup:container:show {container : Container ID or name}';

    protected $description = 'Show a backup container';

    public function handle(): int
    {
        $containerArg = $this->argument('container');
        if (! is_string($containerArg)) {
            $this->error('Invalid container identifier.');

            return 1;
        }

        $container = BackupContainer::findByIdOrName($containerArg);
        if ($container === null) {
            $this->error("Backup container '{$containerArg}' not found.");

            return 1;
        }

        $this->info('Backup container:');
        $this->line("  ID: {$container->id}");
        $this->line("  Name: {$container->name}");
        $this->line("  Driver: {$container->driver}");
        $this->line("  Location: {$container->location}");
        $this->line('  Has credentials: '.($container->credentials !== null ? 'yes' : 'no'));
        $this->line("  Created: {$container->created_at}");
        $this->line("  Updated: {$container->updated_at}");

        return 0;
    }
}
