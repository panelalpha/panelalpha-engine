<?php

namespace App\Console\Commands\Wp;

use App\Models\User;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Illuminate\Console\Command;

class WpCommand extends Command
{
    use SelectsWordPressInstall;

    protected $signature = 'wordpress:command
                            {arg* : WP-CLI arguments}';

    protected $description = "Run a WP-CLI command on a project's WordPress install";

    public function handle(): int
    {
        $user = User::findByUsernameOrFail((string) $this->argument('username'));
        $path = $this->option('path');
        $service = $this->option('service');
        $args = array_values(array_map('strval', (array) $this->argument('arg')));

        try {
            $result = WpCli::for(
                $user,
                is_string($path) && $path !== '' ? $path : null,
                service: is_string($service) && $service !== '' ? $service : null,
                skipPlugins: (bool) $this->option('skip-plugins'),
            )->run($args);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->line('stdout:');
        $this->info(trim($result['stdout']));
        $this->line('stderr:');
        $this->warn(trim($result['stderr']));
        $this->line('exit_code: '.$result['exit_code']);

        return 0;
    }
}
