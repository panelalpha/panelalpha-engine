<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeActivateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:activate
                            {slug : Theme slug}';

    protected $description = 'Activate a WordPress theme by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->activate((string) $this->argument('slug'));
    }
}
