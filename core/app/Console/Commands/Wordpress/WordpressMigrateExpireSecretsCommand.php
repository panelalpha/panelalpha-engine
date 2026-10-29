<?php

namespace App\Console\Commands\Wordpress;

use App\Models\WordpressMigration;
use Illuminate\Console\Command;

/**
 * Clears the wp-admin password and tokens of WordPress imports that have had
 * no activity for WordpressMigration::SECRETS_KEPT_DAYS; a resume asks for the secret again.
 */
class WordpressMigrateExpireSecretsCommand extends Command
{
    protected $signature = 'wordpress:migrate:expire-secrets';

    protected $description = 'Clear the stored secrets of WordPress imports idle for '.WordpressMigration::SECRETS_KEPT_DAYS.' days';

    public function handle(): int
    {
        $this->line(WordpressMigration::expireIdleSecrets().' cleared');

        return self::SUCCESS;
    }
}
