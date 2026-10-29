<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:get
                            {slug : Plugin slug}
                            {--fields= : Comma-separated fields to return}';

    protected $description = 'Read one installed WordPress plugin';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->get((string) $this->argument('slug'), $this->optionalOption('fields'));
    }
}
