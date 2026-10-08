<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 08: DinD app surfaces use App\System\Project, not connect() / Lib Git.
 */
final class DindAppSurfacesCutoverTest extends TestCase
{
    public function test_dind_app_surface_controllers_do_not_call_connect(): void
    {
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'User';
        $paths = [
            'GitController.php',
            'ContainerController.php',
            'AppHealthController.php',
            'DeployLogController.php',
            'SshController.php',
            'AppUserController.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $relative);
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

    public function test_git_http_requests_use_system_git_ref(): void
    {
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Requests' . DIRECTORY_SEPARATOR . 'Git';
        $paths = [
            'GitConnectRequest.php',
            'GitChangeBranchRequest.php',
            'GitCommitsRequest.php',
            'GitRevertRequest.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $contents = file_get_contents($root . DIRECTORY_SEPARATOR . $relative);
            if ($contents === false) {
                $hits[] = $relative . ' (unreadable)';
                continue;
            }
            if (preg_match('/Lib\\\\Apis\\\\System\\\\User\\\\Project\\\\Git/', $contents) === 1) {
                $hits[] = $relative;
            }
            if (!str_contains($contents, 'App\\System\\Project\\Git\\Ref')) {
                $hits[] = $relative . ' (missing System Ref import)';
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_cloudflare_integration_does_not_use_lib_system_or_connect(): void
    {
        $path = dirname(__DIR__, 4) . '/core/app/Integrations/Tunnels/Cloudflare.php';
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('Lib\\Apis\\System', $contents);
        $this->assertStringNotContainsString('->connect()', $contents);
        $this->assertStringContainsString('App\\System\\Project\\Dind', $contents);
    }
}
