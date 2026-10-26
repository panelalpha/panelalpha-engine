<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpThemeListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:theme:list
                            {--fields= : Comma-separated fields to return}
                            {--status= : Comma-separated statuses to list}';

    protected $description = 'List installed WordPress themes for a project';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->themes()->list($this->optionalOption('fields'), $this->optionalOption('status'));
    }
}
