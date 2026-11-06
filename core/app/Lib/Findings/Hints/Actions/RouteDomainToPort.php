<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Deploy\Inspect\Report\PortsReport;

/** The site routed to the port the application really listens on. */
final class RouteDomainToPort extends ProxyRuleHint
{
    public function resolves(): array
    {
        return [PortsReport::PORT_NOT_DETECTED];
    }

    protected function transport(): string
    {
        return 'http';
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function choose(): array
    {
        return ['listen_port' => 443, 'server_name' => '{domain}', 'upstream_port' => null];
    }
}
