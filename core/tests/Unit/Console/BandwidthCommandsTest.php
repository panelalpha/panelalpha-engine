<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Usage\DomainBandwidthCommand;
use App\Console\Commands\Usage\DomainVisitorsBreakdownCommand;
use App\Console\Commands\Usage\DomainVisitorsCommand;
use App\Console\Commands\Usage\ProjectBandwidthCommand;
use App\Console\Commands\Usage\ProjectUsageCommand;
use Tests\TestCase;

class BandwidthCommandsTest extends TestCase
{
    public function test_usage_and_bandwidth_commands_dispatch_the_get_routes(): void
    {
        $this->assertStringContainsString('GET /projects/{username}/usage', (new ProjectUsageCommand())->getDescription());
        $this->assertStringContainsString('GET /projects/{username}/bandwidth', (new ProjectBandwidthCommand())->getDescription());
        $this->assertStringContainsString(
            'GET /projects/{username}/domains/{domain}/bandwidth',
            (new DomainBandwidthCommand())->getDescription()
        );
        $this->assertStringContainsString(
            'GET /projects/{username}/domains/{domain}/visitors',
            (new DomainVisitorsCommand())->getDescription()
        );
        $this->assertStringContainsString(
            'GET /projects/{username}/domains/{domain}/visitors/{dimension}',
            (new DomainVisitorsBreakdownCommand())->getDescription()
        );
    }
}
