<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 10: Deploy package host entry uses App\System, not Lib\Apis\System.
 */
final class DeployPackageHostEntryCutoverTest extends TestCase
{
    /** @return list<string> */
    private function deployHostEntryFiles(): array
    {
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app';

        return [
            $root . '/Lib/Deploy/Telemetry/Telemetry.php',
            $root . '/Lib/Deploy/Telemetry/HostFacts.php',
            $root . '/Lib/Deploy/Telemetry/Spool.php',
            $root . '/Lib/Deploy/Telemetry/TelemetryShipper.php',
            $root . '/Lib/Deploy/DeployLog/LogStorage.php',
        ];
    }

    public function test_deploy_host_entry_files_do_not_reference_lib_apis_system(): void
    {
        $hits = [];
        foreach ($this->deployHostEntryFiles() as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                $hits[] = $file . ' (unreadable)';
                continue;
            }
            if (str_contains($contents, 'Lib\\Apis\\System')) {
                $hits[] = basename($file);
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_telemetry_probe_uses_model_project_seam(): void
    {
        $telemetry = file_get_contents(dirname(__DIR__, 4) . '/core/app/Lib/Deploy/Telemetry/Telemetry.php');
        $this->assertIsString($telemetry);
        $this->assertStringContainsString('->project()->runtime()', $telemetry);
        $this->assertStringNotContainsString('connect()->project()', $telemetry);
    }
}
