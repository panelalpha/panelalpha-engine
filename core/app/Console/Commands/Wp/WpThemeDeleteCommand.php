<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeDeleteCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:delete
                            {slug : Theme slug}';

    protected $description = 'Delete a WordPress theme by slug';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->delete((string) $this->argument('slug'));
    }
}
