<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpCoreUpdateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:core:update
                            {version : Target WordPress version}';

    protected $description = 'Update WordPress core to a target version';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->core()->update((string) $this->argument('version'));
    }
}
