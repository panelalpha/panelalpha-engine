<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:get
                            {slug : Theme slug}
                            {--fields= : Comma-separated fields to return}';

    protected $description = 'Read one installed WordPress theme';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->get((string) $this->argument('slug'), $this->optionalOption('fields'));
    }
}
