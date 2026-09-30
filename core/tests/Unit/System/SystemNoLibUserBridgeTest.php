<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

final class SystemNoLibUserBridgeTest extends TestCase
{
    private string $systemRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemRoot = dirname(__DIR__, 3) . '/app/System';
    }

    public function test_change_webserver_trio_exists_on_app_system(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/app/System.php');

        $this->assertStringContainsString('function getLatestChangeWebserverInfo', $source);
        $this->assertStringContainsString('function runChangeWebserverScript', $source);
        $this->assertStringContainsString('function isChangeWebserverScriptRunning', $source);
    }

    public function test_webserver_drivers_do_not_call_connect(): void
    {
        $paths = [
            $this->systemRoot . '/Webserver/Apache.php',
            $this->systemRoot . '/Webserver/Nginx.php',
            $this->systemRoot . '/Webserver/Litespeed.php',
            $this->systemRoot . '/Webserver/Openlitespeed.php',
            $this->systemRoot . '/Webserver/NginxProxy.php',
            $this->systemRoot . '/Webserver/RoutingCompiler.php',
            $this->systemRoot . '/Project/PhpHosting/FpmApacheStack.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $active = preg_replace('/^\s*\/\/.*$/m', '', $source) ?? $source;
            $this->assertStringNotContainsString('->connect()', $active, $path);
        }
    }

    public function test_lib_user_deploy_mechanics_is_gone(): void
    {
        $this->assertFileDoesNotExist(
            $this->systemRoot . '/Project/Deployment/LibUserDeployMechanics.php'
        );
    }

    public function test_app_system_tree_does_not_reference_lib_apis_system(): void
    {
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->systemRoot));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }
            $active = preg_replace('/@see[^\n]*/', '', $source) ?? $source;
            if (preg_match('/App\\\\Lib\\\\Apis\\\\System/', $active) === 1) {
                $hits[] = str_replace($this->systemRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }
}
