<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpMaintenanceSetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:maintenance:set
                            {enabled : Whether maintenance mode should be on (1/0/true/false)}';

    protected $description = 'Turn WordPress maintenance mode on or off';

    protected function perform(WordPress $wordpress): mixed
    {
        $enabled = filter_var($this->argument('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new \InvalidArgumentException('enabled must be a boolean (1/0/true/false).');
        }

        return $wordpress->maintenance()->set($enabled);
    }
}
