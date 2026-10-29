<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpCacheFlushCommand extends WordPressCommand
{
    protected $signature = 'wordpress:cache:flush';

    protected $description = 'Flush the WordPress object cache';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->cache()->flush();
    }
}
