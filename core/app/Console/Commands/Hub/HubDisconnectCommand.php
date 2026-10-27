<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\HubClient;
use App\Lib\Hub\HubException;
use App\Lib\Hub\HubLifecycle;
use App\Models\HubConnection;
use Illuminate\Console\Command;

/**
 * Disconnect from PanelAlpha Connect. The tokens are revoked and the
 * connection forgotten first, so the Hub loses access at once whether or not
 * it hears about it: the notice is best effort, the revocation is not.
 */
class HubDisconnectCommand extends Command
{
    protected $signature = 'hub:disconnect';

    protected $description = 'Disconnect this engine from PanelAlpha Connect';

    public function handle(): int
    {
        $connection = HubConnection::active();
        $revoked = HubLifecycle::forget('operator');

        if ($connection !== null) {
            try {
                $response = (new HubClient($connection->hub_url))->disconnect($connection, 'operator');
                if (! $response->ok() && $response->status !== 403) {
                    $this->warn('The Hub answered: '.$response->message()." (HTTP {$response->status})");
                }
            } catch (HubException $e) {
                $this->warn('Could not tell the Hub ('.$e->getMessage().'). It loses access anyway.');
            }
        }

        if ($connection === null) {
            $this->line($revoked > 0
                ? "Not connected; revoked {$revoked} leftover Hub token(s)."
                : 'Not connected to PanelAlpha Connect.');

            return self::SUCCESS;
        }

        $this->info('Disconnected from '.($connection->account_name ?? 'account '.$connection->account_id).'.');

        return self::SUCCESS;
    }
}
