<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpMaintenanceGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:maintenance:get';

    protected $description = 'Read whether WordPress maintenance mode is enabled';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->maintenance()->get();
    }
}
