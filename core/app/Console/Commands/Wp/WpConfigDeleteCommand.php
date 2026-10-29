<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpConfigDeleteCommand extends WordPressCommand
{
    protected $signature = 'wordpress:config:delete
                            {constant : Constant name}
                            {--type=constant : constant, variable or all}';

    protected $description = 'Delete a WordPress wp-config constant';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->config()->delete((string) $this->argument('constant'), (string) $this->option('type'));
    }
}
