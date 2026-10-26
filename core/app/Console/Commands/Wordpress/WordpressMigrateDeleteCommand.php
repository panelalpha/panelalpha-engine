<?php

namespace App\Console\Commands\Wordpress;

use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Deletes the record of a completed, failed or cancelled WordPress migration.
 */
class WordpressMigrateDeleteCommand extends Command
{
    protected $signature = 'wordpress:migrate:delete
                            {id : Migration record id}';

    protected $description = 'Delete a finished WordPress site migration record';

    public function handle(): int
    {
        $migration = WordpressMigrateStatusCommand::find((string) $this->argument('id'));

        try {
            $migration->deleteEnded();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Deleted WordPress migration '.$migration->id);

        return self::SUCCESS;
    }
}
