<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserApplicationPasswordListCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:application-password:list
                            {user : WordPress user login or ID}';

    protected $description = 'List application passwords for a WordPress user';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->applicationPasswords()->list((string) $this->argument('user'));
    }
}
