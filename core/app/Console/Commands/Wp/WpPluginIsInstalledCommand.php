<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginIsInstalledCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:is-installed
                            {slug : Plugin slug}';

    protected $description = 'Check whether a WordPress plugin is installed';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->isInstalled((string) $this->argument('slug'));
    }
}
