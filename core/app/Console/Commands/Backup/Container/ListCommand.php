<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ListCommand extends Command
{
    protected $signature = 'backup:container:list';

    protected $description = 'List backup containers';

    public function handle(): int
    {
        /** @var Collection<array-key, BackupContainer> $containers */
        $containers = BackupContainer::query()->orderBy('name')->get();

        if ($containers->isEmpty()) {
            $this->info('No backup containers found.');

            return 0;
        }

        $rows = [];
        foreach ($containers as $container) {
            $rows[] = [
                'id' => $container->id,
                'name' => $container->name,
                'driver' => $container->driver,
                'location' => $container->location,
            ];
        }

        $this->table(['ID', 'Name', 'Driver', 'Location'], $rows);

        return 0;
    }
}
