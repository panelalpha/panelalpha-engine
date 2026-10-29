<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpOptionSetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:option:set
                            {option : Option name}
                            {value : Option value}';

    protected $description = 'Update a WordPress option';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->options()->set((string) $this->argument('option'), (string) $this->argument('value'));
    }
}
