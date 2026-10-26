<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserCreateCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:create
                            {login : WordPress user login}
                            {email : WordPress user email}
                            {password : Password the caller provides}
                            {--role= : WordPress role}
                            {--display-name= : Display name}
                            {--first-name= : First name}
                            {--last-name= : Last name}
                            {--send-email : Email the new user their account details}';

    protected $description = 'Create a WordPress user with a caller-provided password';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->users()->create([
            'login' => (string) $this->argument('login'),
            'email' => (string) $this->argument('email'),
            'password' => (string) $this->argument('password'),
            'role' => $this->optionalOption('role'),
            'display_name' => $this->optionalOption('display-name'),
            'first_name' => $this->optionalOption('first-name'),
            'last_name' => $this->optionalOption('last-name'),
            'send_email' => (bool) $this->option('send-email'),
        ]);
    }
}
