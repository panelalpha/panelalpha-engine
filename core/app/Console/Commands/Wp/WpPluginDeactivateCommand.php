<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginDeactivateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:deactivate
                            {slug : Plugin slug}';

    protected $description = 'Deactivate a WordPress plugin by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->deactivate((string) $this->argument('slug'));
    }
}
