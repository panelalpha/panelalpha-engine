<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpOptionListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:option:list';

    protected $description = 'List WordPress options';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->options()->list();
    }
}
