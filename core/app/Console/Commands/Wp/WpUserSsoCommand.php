<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserSsoCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:sso
                            {user : WordPress user login or ID}';

    protected $description = 'Create a one-time SSO URL for a WordPress user';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->sso()->create((string) $this->argument('user'));
    }
}
