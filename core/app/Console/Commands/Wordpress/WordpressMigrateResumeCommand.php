<?php

namespace App\Console\Commands\Wordpress;

use App\Jobs\MigrateWordpress;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Resumes a failed or stalled WordPress migration and queues its job again.
 */
class WordpressMigrateResumeCommand extends Command
{
    protected $signature = 'wordpress:migrate:resume
                            {id : Migration record id}';

    protected $description = 'Resume a failed or stalled WordPress site migration';

    public function handle(): int
    {
        $migration = WordpressMigrateStatusCommand::find((string) $this->argument('id'));

        try {
            $migration->resume();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        MigrateWordpress::dispatch($migration->id);

        $this->line((string) $migration->id);

        return self::SUCCESS;
    }
}
