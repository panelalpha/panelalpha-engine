<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginUpdateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:update
                            {slug : Plugin slug}';

    protected $description = 'Update a WordPress plugin by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->update((string) $this->argument('slug'));
    }
}
