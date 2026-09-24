<?php

namespace App\Console\Commands\DockerHub;

use App\Support\DockerHubLogin;
use App\System;
use Illuminate\Console\Command;

/** Take registry-proxy back to anonymous Docker Hub pulls. */
class DockerHubLogoutCommand extends Command
{
    protected $signature = 'docker-hub:logout';

    protected $description = 'Remove the Docker Hub login registry-proxy pulls with';

    public function handle(): int
    {
        $login = new DockerHubLogin(new System());
        try {
            $login->clear();
        } catch (\Exception $e) {
            $this->error('Could not remove it: ' . trim($e->getMessage()));

            return self::FAILURE;
        }

        $this->info(($login->current()['username'] ?? null) === ''
            ? 'registry-proxy pulls from Docker Hub anonymously again.'
            : 'Removed from .env, but registry-proxy is not running to confirm it.');

        return self::SUCCESS;
    }
}
