<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpAutoUpdateGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:auto-update:get';

    protected $description = 'Read WordPress auto-update settings for core, plugins, and themes';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->autoUpdate()->get();
    }
}
