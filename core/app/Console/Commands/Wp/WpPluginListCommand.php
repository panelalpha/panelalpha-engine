<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpPluginListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:plugin:list
                            {--fields= : Comma-separated fields to return}
                            {--status= : Comma-separated statuses to list}';

    protected $description = 'List installed WordPress plugins for a project';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->plugins()->list($this->optionalOption('fields'), $this->optionalOption('status'));
    }
}
