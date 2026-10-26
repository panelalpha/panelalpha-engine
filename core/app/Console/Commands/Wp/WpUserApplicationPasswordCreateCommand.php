<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserApplicationPasswordCreateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:application-password:create
                            {user : WordPress user login or ID}
                            {name : Unique application name}
                            {--app-id= : Optional application ID}';

    protected $description = 'Create an application password (secret printed once)';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->applicationPasswords()->create((string) $this->argument('user'), (string) $this->argument('name'), $this->optionalOption('app-id'));
    }
}
