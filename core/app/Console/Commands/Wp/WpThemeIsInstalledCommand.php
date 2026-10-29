<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeIsInstalledCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:is-installed
                            {slug : Theme slug}';

    protected $description = 'Check whether a WordPress theme is installed';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->isInstalled((string) $this->argument('slug'));
    }
}
