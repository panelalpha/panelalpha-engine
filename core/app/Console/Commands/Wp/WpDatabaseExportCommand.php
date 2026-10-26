<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpDatabaseExportCommand extends WordPressCommand
{
    protected $signature = 'wordpress:database:export
                            {file : Destination SQL file path inside the project}
                            {--tables= : Comma-separated tables to export; omitted, all}';

    protected $description = 'Export the WordPress install database to a file';

    protected function perform(WordPress $wordpress): mixed
    {
        $tables = $this->optionalOption('tables');

        return $wordpress->database()->export(
            (string) $this->argument('file'),
            $tables !== null ? array_values(array_filter(explode(',', $tables))) : [],
        );
    }
}
