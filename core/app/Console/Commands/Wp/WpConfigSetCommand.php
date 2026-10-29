<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpConfigSetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:config:set
                            {constant : Constant name}
                            {value : Constant value}
                            {--raw : Place the value as raw PHP instead of a quoted string}
                            {--type=constant : constant, variable or all}';

    protected $description = 'Set a WordPress wp-config constant';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->config()->set(
            (string) $this->argument('constant'),
            (string) $this->argument('value'),
            (bool) $this->option('raw'),
            (string) $this->option('type'),
        );
    }
}
