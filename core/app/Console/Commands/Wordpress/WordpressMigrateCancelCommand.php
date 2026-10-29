<?php

namespace App\Console\Commands\Wordpress;

use App\Jobs\MigrateWordpress;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Cancels a WordPress migration and queues the job that removes what it made.
 */
class WordpressMigrateCancelCommand extends Command
{
    protected $signature = 'wordpress:migrate:cancel
                            {id : Migration record id}';

    protected $description = 'Cancel a WordPress site migration and remove what it made';

    public function handle(): int
    {
        $migration = WordpressMigrateStatusCommand::find((string) $this->argument('id'));

        try {
            $migration->requestCancel();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        MigrateWordpress::dispatch($migration->id);

        $this->line((string) $migration->id);

        return self::SUCCESS;
    }
}
