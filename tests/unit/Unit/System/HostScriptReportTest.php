<?php

namespace Tests\Unit\System;

use App\System\HostScriptReport;
use PHPUnit\Framework\TestCase;

class HostScriptReportTest extends TestCase
{
    public function test_the_engine_reads_the_files_the_broker_writes(): void
    {
        $tenantNetwork = (string) file_get_contents(dirname(__DIR__, 4).'/dockerfiles/root-broker/rootbroker/tenantports.py');
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 4).'/dockerfiles/entrypoint-root-broker.sh');

        $this->assertStringContainsString("\nFAILED = '".HostScriptReport::TENANT_NETWORK_FIREWALL."'\n", $tenantNetwork);
        $this->assertStringContainsString("\nfirewall_failed=".HostScriptReport::HOST_FIREWALL."\n", $entrypoint);
    }
}
