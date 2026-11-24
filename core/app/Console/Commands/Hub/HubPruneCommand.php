<?php

namespace App\Console\Commands\Hub;

use App\Models\HubAccessLog;
use App\Models\HubConnection;
use App\Models\HubExchange;
use App\Models\HubSsoLogin;
use Illuminate\Console\Command;

/** Keep PanelAlpha Connect's calls, this engine's reports and the used login tickets for 30 days. */
class HubPruneCommand extends Command
{
    protected $signature = 'hub:prune';

    protected $description = 'Drop PanelAlpha Connect log rows older than 30 days and a replaced Hub secret past its minutes';

    public function handle(): int
    {
        $log = HubAccessLog::query()->where('at', '<', now()->subDays(HubAccessLog::KEEP_DAYS))->delete();
        $exchanges = HubExchange::query()->where('at', '<', now()->subDays(HubExchange::KEEP_DAYS))->delete();
        $logins = HubSsoLogin::query()->where('at', '<', now()->subDays(HubSsoLogin::KEEP_DAYS))->delete();
        HubConnection::query()->whereNotNull('hub_secret_previous')->where('hub_secret_previous_until', '<', now())
            ->update(['hub_secret_previous' => null, 'hub_secret_previous_until' => null]);

        $this->line("Pruned {$log} access log row(s), {$exchanges} report row(s) and {$logins} login row(s).");

        return self::SUCCESS;
    }
}
