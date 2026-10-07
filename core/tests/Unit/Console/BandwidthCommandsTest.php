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
    public function test_usage_and_bandwidth_help_names_no_http_route(): void
    {
        $commands = [
            new ProjectUsageCommand(),
            new ProjectBandwidthCommand(),
            new DomainBandwidthCommand(),
            new DomainVisitorsCommand(),
            new DomainVisitorsBreakdownCommand(),
        ];
        foreach ($commands as $command) {
            $this->assertDoesNotMatchRegularExpression('#\b(GET|POST|PUT|DELETE)\b|/projects/#', $command->getDescription(), $command->getName() ?? '');
        }
        $this->assertSame('Show resource usage for a project', (new ProjectUsageCommand())->getDescription());
    }
}
