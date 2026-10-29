<?php

namespace App\Console\Commands\Wp;

use App\System\Project\PhpHosting\WordPress\WordPress;

class WpUserApplicationPasswordDeleteCommand extends WordPressCommand
{
    protected $signature = 'wordpress:user:application-password:delete
                            {user : WordPress user login or ID}
                            {uuid : Application password UUID}';

    protected $description = 'Delete an application password by UUID';

    protected function perform(WordPress $wordpress): mixed
    {
        return $wordpress->applicationPasswords()->delete((string) $this->argument('user'), (string) $this->argument('uuid'));
    }
}
