<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Deploy\Inspect\Report\PortsReport;

/** A port the engine leaves alone, served over HTTP on a name of the project's own. */
final class RoutePortHttp extends ProxyRuleHint
{
    public function resolves(): array
    {
        return [PortsReport::SECONDARY_PORT];
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
        return ['listen_port' => null, 'server_name' => null, 'upstream_port' => '{port}'];
    }
}
