<?php

namespace App\Console\Commands\Wordpress;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\ImportedDomainSwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Visits every imported site still waiting for its domain's DNS: once the
 * domain resolves here and answers, its certificate is requested and the
 * preview tunnel removed. One domain's failure is not the next domain's.
 */
class WordpressMigrateDnsSwitchCommand extends Command
{
    protected $signature = 'wordpress:migrate:dns-switch';

    protected $description = 'Issue certificates and remove preview tunnels for imported domains now pointing here';

    public function handle(ImportedDomainSwitch $switch): int
    {
        $switched = 0;
        $failed = 0;

        foreach (WordpressMigration::awaitingDnsSwitch() as $migration) {
            try {
                $switched += $switch->check($migration) ? 1 : 0;
            } catch (Throwable $e) {
                $this->error("could not switch {$migration->target_label}: ".$e->getMessage());
                Log::warning("WordPress import DNS switch failed for {$migration->target_label}: ".$e->getMessage());
                $failed++;
            }
        }

        $this->line("{$switched} switched, {$failed} failed");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
