<?php

namespace App\Console\Commands\System;

use App\System;
use Illuminate\Console\Command;

class ProxyRuleRefreshCertificatesCommand extends Command
{
    protected $signature = 'proxy:rule:refresh-certificates';

    protected $description = 'Serve each proxy rule on port 443 with the certificate on the server that covers it now';

    public function handle(): int
    {
        $reloaded = app(System::class)->webserver()->refreshProxyRuleCertificates();
        $this->info($reloaded ? 'Proxy rule certificates changed; the webserver was reloaded.' : 'Proxy rule certificates are up to date.');

        return 0;
    }
}
