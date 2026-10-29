<?php

namespace App\Console\Commands\Wordpress;

use App\Jobs\MigrateWordpress;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Resumes a failed or stalled WordPress migration and queues its job again.
 * A secret cleared for inactivity is read again as wordpress:migrate reads it.
 */
class WordpressMigrateResumeCommand extends Command
{
    use ReadsMigrationSecret;

    protected $signature = 'wordpress:migrate:resume
                            {id : Migration record id}';

    protected $description = 'Resume a failed or stalled WordPress site migration';

    public function handle(): int
    {
        $migration = WordpressMigrateStatusCommand::find((string) $this->argument('id'));
        $missing = $migration->missingSourceSecret();
        $secret = $missing === null ? null : $this->migrationSecret($missing);
        if ($missing !== null && $secret === null) {
            return self::FAILURE;
        }

        try {
            $migration->resume($secret);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        MigrateWordpress::dispatch($migration->id);

        $this->line((string) $migration->id);

        return self::SUCCESS;
    }
}
