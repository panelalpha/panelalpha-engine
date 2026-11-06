<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Deploy\Inspect\Report\PortsReport;

/** A port the engine leaves alone, passed through on a free listen port. */
final class RoutePortTcp extends ProxyRuleHint
{
    public function resolves(): array
    {
        return [PortsReport::SECONDARY_PORT];
    }

    protected function transport(): string
    {
        return 'tcp';
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function choose(): array
    {
        return ['listen_port' => null, 'upstream_port' => '{port}'];
    }
}
