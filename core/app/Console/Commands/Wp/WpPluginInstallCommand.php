<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginInstallCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:install
                            {slug : Plugin slug}
                            {--force : Reinstall over an existing copy}';

    protected $description = 'Install a WordPress plugin by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->install((string) $this->argument('slug'), (bool) $this->option('force'));
    }
}
