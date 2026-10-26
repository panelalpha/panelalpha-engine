<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpConfigGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:config:get
                            {constant : Constant name}
                            {--type=constant : constant, variable or all}
                            {--json : Decode the value from JSON}';

    protected $description = 'Read a WordPress wp-config constant';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->config()->get(
            (string) $this->argument('constant'),
            (string) $this->option('type'),
            (bool) $this->option('json'),
        );
    }
}
