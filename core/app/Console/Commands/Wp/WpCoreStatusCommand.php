<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpCoreStatusCommand extends WordPressCommand
{
    protected $signature = 'wordpress:core:status';

    protected $description = 'Read WordPress core version, whether it is installed, and its state (installed, not_installed, no_config, db_unreachable, no_core, unknown)';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->core()->status();
    }
}
