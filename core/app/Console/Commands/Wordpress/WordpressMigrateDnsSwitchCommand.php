<?php

namespace App\Console\Commands\Wordpress;

use App\System\Project\PhpHosting\WordPress\Migration\ImportedDomainSwitch;
use Illuminate\Console\Command;

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
        $result = $switch->checkAwaiting();
        foreach ($result['failures'] as $failure) {
            $this->error("could not switch {$failure}");
        }
        $this->line("{$result['switched']} switched, ".count($result['failures']).' failed');

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
