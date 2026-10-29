<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpConfigListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:config:list
                            {--type=constant : constant, variable or all}';

    protected $description = 'List WordPress wp-config constants';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->config()->list((string) $this->option('type'));
    }
}
