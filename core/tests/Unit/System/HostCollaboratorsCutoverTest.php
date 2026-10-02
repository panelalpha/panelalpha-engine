<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 03: host entry points construct App\System, not Lib\Apis\System.
 */
final class HostCollaboratorsCutoverTest extends TestCase
{
    public function test_host_entry_layers_do_not_import_lib_apis_system(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app';
        $paths = [
            'Http/Controllers/FirewallController.php',
            'Http/Controllers/ModsecController.php',
            'Http/Controllers/IpController.php',
            'Http/Controllers/SystemController.php',
            'Http/Controllers/HttpAcmeChallengeController.php',
            'Http/Controllers/TaskController.php',
            'Http/Controllers/PhpController.php',
            'Http/Controllers/LighthouseController.php',
            'Http/Controllers/User/DeployLogController.php',
            'Http/Controllers/User/ProxyRuleController.php',
            'Lib/Helper.php',
            'Lib/Task/ProcessTreeKiller.php',
            'Logging/CustomRotatingFileHandler.php',
            'Lib/Deploy/DeployLog/LogStorage.php',
            'Lib/HttpAcmeChallengeStore.php',
            'Lib/Deploy/Telemetry/HostFacts.php',
            'Lib/Deploy/Telemetry/TelemetryShipper.php',
            'Lib/Ssl/EngineCertificate.php',
            'Lib/Ssl/AcmeAccountKey.php',
            'Lib/Ssl/EngineCertificateRequest.php',
            'Console/Commands/System/RebuildEximConfig.php',
            'Console/Commands/System/RebuildSftpAccounts.php',
            'Console/Commands/System/RebuildModsecurityConfig.php',
            'Console/Commands/System/RebuildDomains.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $contents = file_get_contents($file);
            if ($contents === false) {
                $hits[] = $relative . ' (unreadable)';
                continue;
            }
            if (preg_match('/use App\\\\Lib\\\\Apis\\\\System;/', $contents) === 1) {
                $hits[] = $relative;
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_exim_and_sftp_console_commands_use_collaborators(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Console'
            . DIRECTORY_SEPARATOR . 'Commands' . DIRECTORY_SEPARATOR . 'System';

        $exim = file_get_contents($root . DIRECTORY_SEPARATOR . 'RebuildEximConfig.php');
        $sftp = file_get_contents($root . DIRECTORY_SEPARATOR . 'RebuildSftpAccounts.php');

        $this->assertIsString($exim);
        $this->assertIsString($sftp);
        $this->assertStringContainsString('->exim()->rebuildEximConfig()', $exim);
        $this->assertStringContainsString('->sftp()->rebuildSftpAccounts()', $sftp);
    }

    public function test_helper_uses_app_system_occupancy_names(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 3) . '/app/Lib/Helper.php');
        $this->assertIsString($helper);
        $this->assertStringContainsString('isUsernameAvailable', $helper);
        $this->assertStringNotContainsString('->usernameAvailable(', $helper);
    }
}
