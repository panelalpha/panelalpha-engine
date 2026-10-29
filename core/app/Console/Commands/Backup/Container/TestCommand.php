<?php

namespace App\Console\Commands\Backup\Container;

use App\Models\BackupContainer;
use Illuminate\Console\Command;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'backup:container:test {container : Container ID or name}';

    protected $description = 'Test backup container connectivity';

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

        try {
            $container->storage()->test();
        } catch (Throwable $e) {
            $message = trim($e->getMessage());
            $this->error($message !== '' ? $message : 'Backup storage connection test failed.');

            return 1;
        }

        $this->info('Backup storage connection test succeeded.');

        return 0;
    }
}
