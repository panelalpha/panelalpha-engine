<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserPasswordCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:password
                            {user : WordPress user login or ID}
                            {password : Password the caller provides}';

    protected $description = 'Set a WordPress user password to a caller-provided value';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->users()->setPassword((string) $this->argument('user'), (string) $this->argument('password'));
    }
}
