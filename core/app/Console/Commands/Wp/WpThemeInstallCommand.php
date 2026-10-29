<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeInstallCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:install
                            {slug : Theme slug}
                            {--force : Reinstall over an existing copy}';

    protected $description = 'Install a WordPress theme by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->install((string) $this->argument('slug'), (bool) $this->option('force'));
    }
}
