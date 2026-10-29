<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserDeleteCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:delete
                            {user : WordPress user login or ID}
                            {--reassign= : Reassign content to this user login or ID}';

    protected $description = 'Delete a WordPress user';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->users()->delete((string) $this->argument('user'), $this->optionalOption('reassign'));
    }
}
