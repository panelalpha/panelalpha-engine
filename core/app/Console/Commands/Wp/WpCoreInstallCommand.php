<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpCoreInstallCommand extends WordPressCommand
{
    protected $signature = 'wordpress:core:install
                            {url : Site URL}
                            {title : Site title}
                            {admin_user : Admin username}
                            {admin_email : Admin email}
                            {admin_password : Admin password}
                            {--skip-email : Do not email the admin about the new site}';

    protected $description = 'Install WordPress core into the project document root';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->core()->install([
            'url' => (string) $this->argument('url'),
            'title' => (string) $this->argument('title'),
            'admin_user' => (string) $this->argument('admin_user'),
            'admin_email' => (string) $this->argument('admin_email'),
            'admin_password' => (string) $this->argument('admin_password'),
            'skip_email' => (bool) $this->option('skip-email'),
        ]);
    }
}
