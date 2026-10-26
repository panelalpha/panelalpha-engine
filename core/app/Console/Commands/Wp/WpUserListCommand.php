<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:list
                            {--role= : Only users with this role}
                            {--fields= : Comma-separated fields to return}';

    protected $description = 'List WordPress users for a project';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->users()->list($this->optionalOption('role'), $this->optionalOption('fields'));
    }
}
