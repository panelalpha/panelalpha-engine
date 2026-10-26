<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginActivateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:activate
                            {slug : Plugin slug}';

    protected $description = 'Activate a WordPress plugin by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->activate((string) $this->argument('slug'));
    }
}
