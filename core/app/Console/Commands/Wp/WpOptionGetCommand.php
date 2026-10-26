<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpOptionGetCommand extends WordPressCommand
{
    protected $signature = 'wordpress:option:get
                            {option : Option name}';

    protected $description = 'Read a WordPress option';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->options()->get((string) $this->argument('option'));
    }
}
