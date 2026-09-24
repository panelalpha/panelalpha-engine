<?php

namespace App\Console\Commands\DockerHub;

use App\Support\DockerHubLogin;
use App\System;
use Illuminate\Console\Command;

/** Which Docker Hub login this engine pulls with, and what limit it has left. */
class DockerHubStatusCommand extends Command
{
    protected $signature = 'docker-hub:status';

    protected $description = 'Show the Docker Hub login registry-proxy pulls with, and its rate limit';

    public function handle(): int
    {
        $login = new DockerHubLogin(new System());
        $current = $login->current();

        if ($current === null) {
            $this->warn('registry-proxy is not running: account pulls go straight to Docker Hub, anonymously.');
        } elseif ($current['username'] === '') {
            $this->line('registry-proxy pulls anonymously: the host IP\'s limit, shared with everything behind it.');
            $this->line('  pae docker-hub:login');
        } else {
            $check = $login->check($current['username'], $current['token']);
            $this->line("registry-proxy pulls as {$current['username']}: " . ($check['ok']
                ? ($check['remaining'] ?? '?') . ' left of ' . ($check['limit'] ?? '?')
                : (string) $check['error']));
        }

        $mirrors = $login->hostMirrors();
        $this->line('Host daemon: ' . match ($mirrors) {
            true => 'mirrors Docker Hub through registry-proxy',
            false => 'pulls Docker Hub directly (bash scripts/install-sysbox.sh sets up the mirror)',
            default => 'could not be asked',
        });

        return self::SUCCESS;
    }
}
