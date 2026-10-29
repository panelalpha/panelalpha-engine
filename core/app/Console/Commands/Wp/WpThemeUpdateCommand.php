<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeUpdateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:update
                            {slug : Theme slug}';

    protected $description = 'Update a WordPress theme by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->update((string) $this->argument('slug'));
    }
}
