<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserUpdateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:update
                            {user : WordPress user login or ID}
                            {--email= : New email}
                            {--role= : New role}
                            {--display-name= : Display name}
                            {--first-name= : First name}
                            {--last-name= : Last name}';

    protected $description = 'Update a WordPress user (password uses wordpress:user:password)';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->users()->update((string) $this->argument('user'), [
            'email' => $this->optionalOption('email'),
            'role' => $this->optionalOption('role'),
            'display_name' => $this->optionalOption('display-name'),
            'first_name' => $this->optionalOption('first-name'),
            'last_name' => $this->optionalOption('last-name'),
        ]);
    }
}
