<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\HubSsoRoutes;
use App\Models\HubConnection;
use App\System;
use Illuminate\Console\Command;

/** Render every vhost once with the route a Hub login ticket arrives on, once the engine is connected to a Hub. */
class HubSsoRoutesCommand extends Command
{
    protected $signature = 'hub:sso-routes {--force : Render them again even if done before}';

    protected $description = 'Give every app domain the route PanelAlpha Connect admin login arrives on';

    public function handle(System $system): int
    {
        if (HubSsoRoutes::areRendered() && ! $this->option('force')) {
            $this->line('Every vhost already has the route.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! HubConnection::isActive()) {
            $this->line('Not connected to PanelAlpha Connect; nothing to render.');

            return self::SUCCESS;
        }

        $reloaded = HubSsoRoutes::render($system);
        $this->info($reloaded ? 'Rendered every vhost with the route.' : 'Rendered every vhost; the webserver reloads when it is up.');

        return self::SUCCESS;
    }
}
