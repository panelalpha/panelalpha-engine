<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginDeleteCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:delete
                            {slug : Plugin slug}';

    protected $description = 'Delete a WordPress plugin by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->delete((string) $this->argument('slug'));
    }
}
