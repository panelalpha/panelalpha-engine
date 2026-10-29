<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpCoreCheckUpdateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:core:check-update';

    protected $description = 'List WordPress core versions newer than the installed one';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->core()->checkUpdate();
    }
}
