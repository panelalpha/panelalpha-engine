<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpConfigCreateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:config:create
                            {dbname : Database name}
                            {dbuser : Database user}
                            {--dbpass= : Database password}
                            {--dbhost= : Database host}
                            {--dbprefix= : Table prefix}
                            {--dbcharset= : Database charset}
                            {--dbcollate= : Database collation}
                            {--locale= : WPLANG locale}
                            {--force : Overwrite an existing wp-config.php}
                            {--skip-check : Do not check the database connection first}';

    protected $description = 'Create wp-config.php for a project\'s WordPress install';

    protected function perform(WordPress $wordpress): mixed
    {
        $fields = [
            'dbname' => (string) $this->argument('dbname'),
            'dbuser' => (string) $this->argument('dbuser'),
            'force' => (bool) $this->option('force'),
            'skip_check' => (bool) $this->option('skip-check'),
        ];
        foreach (['dbpass', 'dbhost', 'dbprefix', 'dbcharset', 'dbcollate', 'locale'] as $key) {
            $value = $this->option($key);
            if (is_string($value)) {
                $fields[$key] = $value;
            }
        }

        return $wordpress->config()->create($fields);
    }
}
