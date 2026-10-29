<?php

namespace App\Console\Commands\Hub;

use App\Lib\Hub\HubClient;
use App\Lib\Hub\SpkiPin;
use App\Models\HubConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tell the Hub about a new :2011 certificate before nginx serves it, so the
 * Hub accepts either pin while it switches. Called by the Let's Encrypt
 * scripts between writing the files and reloading nginx; it always exits 0,
 * because a renewal must never wait on the Hub.
 */
class HubTlsAnnounceCommand extends Command
{
    protected $signature = 'hub:tls-announce {certificate? : The new certificate (default: the served one)}';

    protected $description = 'Announce a new engine certificate to PanelAlpha Connect';

    public function handle(): int
    {
        try {
            $connection = HubConnection::active();
            if ($connection === null) {
                return self::SUCCESS;
            }

            $path = $this->argument('certificate');
            $pin = SpkiPin::fromFile(is_string($path) && $path !== '' ? $path : SpkiPin::servedCertificatePath());
            if ($pin === $connection->tls_spki) {
                $this->line('The key did not change; nothing to announce.');

                return self::SUCCESS;
            }

            $response = (new HubClient($connection->hub_url))->announcePin($connection, $pin);
            if (! $response->ok()) {
                $this->warnAndLog('The Hub did not accept the new certificate pin: '.$response->message()
                    ." (HTTP {$response->status}). If it reports tls_pin_mismatch, run `pae hub:connect --replace`.");

                return self::SUCCESS;
            }

            $connection->forceFill(['tls_spki' => $pin])->save();
            $this->info('Announced the new certificate to PanelAlpha Connect.');
        } catch (\Throwable $e) {
            $this->warnAndLog('Could not announce the new certificate to PanelAlpha Connect: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function warnAndLog(string $message): void
    {
        $this->warn($message);
        Log::warning($message);
    }
}
