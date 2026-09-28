<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 09: provision/rebuild/teardown through App\System\Project.
 */
final class ProvisionCutoverTest extends TestCase
{
    public function test_provision_entry_layers_do_not_call_connect(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app';
        $paths = [
            'Http/Controllers/UserController.php',
            'Http/Controllers/User/StagingController.php',
            'Console/Commands/Users/Rebuild.php',
            'Console/Commands/Users/RecoverDind.php',
            'Console/Commands/Users/FixFilePermissions.php',
            'Console/Commands/Users/RebuildQuotas.php',
            'Console/Commands/Users/Attach.php',
            'Console/Commands/Users/CleanupDisconnectedDomains.php',
            'Console/Commands/Projects/ProjectStagingCommand.php',
            'System/Project.php',
            'System/Services/Sftp.php',
            'System/Project/Sftp.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $contents = file_get_contents($file);
            if ($contents === false) {
                $hits[] = $relative . ' (unreadable)';
                continue;
            }
            if (preg_match('/->connect\\(/', $contents) === 1) {
                $hits[] = $relative;
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_product_delete_lives_on_project_destroy(): void
    {
        $appRoot = dirname(__DIR__, 3) . '/app';
        $source = file_get_contents($appRoot . '/System/Project.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('function destroy(', $source);
        $this->assertStringContainsString('tearDownHosting()', $source);
        $this->assertStringNotContainsString('->connect()->delete()', $source);
        $this->assertFileDoesNotExist($appRoot . '/Lib/Apis/UserAccountDeletion.php');

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && str_contains($contents, 'UserAccountDeletion')) {
                $hits[] = substr($file->getPathname(), strlen($appRoot) + 1);
            }
        }
        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_project_aggregate_exposes_teardown_without_environment_rebuild(): void
    {
        $appRoot = dirname(__DIR__, 3) . '/app';
        $source = file_get_contents($appRoot . '/System/Project.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('function tearDownHosting(', $source);
        $this->assertStringContainsString('function prepareLinuxIsolation(', $source);
        $this->assertStringContainsString('function recreateOuterCompose(', $source);
        $this->assertStringContainsString('function deployment(', $source);
        $this->assertStringNotContainsString('EnvironmentRebuild', $source);
        $this->assertDoesNotMatchRegularExpression('/function rebuild\s*\(/', $source);

        $this->assertFileDoesNotExist($appRoot . '/System/Project/EnvironmentRebuild.php');

        // The template fallback used to be written out at each call site, which
        // is what this checked for by name. It now lives once on the aggregate;
        // the callers just ask for a rebuild. DeploymentWiringTest covers the
        // branch itself.
        $this->assertStringContainsString('function rebuildFromSource(', $source);
        $this->assertStringContainsString('prepareLinuxIsolation', $source);

        foreach ([
            '/Http/Controllers/UserController.php',
            '/Console/Commands/Users/Rebuild.php',
            '/Console/Commands/Users/RecoverDind.php',
        ] as $caller) {
            $callerSource = file_get_contents($appRoot . $caller);
            $this->assertIsString($callerSource);
            $this->assertStringContainsString('rebuildFromSource', $callerSource);
            $this->assertStringNotContainsString(
                'prepareLinuxIsolation',
                $callerSource,
                $caller . ' must not re-implement the template fallback'
            );
        }
    }
}
