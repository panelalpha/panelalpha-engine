<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpDatabaseImportCommand extends WordPressCommand
{
    protected $signature = 'wordpress:database:import
                            {file : Source SQL file path inside the project}';

    protected $description = 'Import a SQL file into the WordPress install database';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->database()->import((string) $this->argument('file'));
    }
}
