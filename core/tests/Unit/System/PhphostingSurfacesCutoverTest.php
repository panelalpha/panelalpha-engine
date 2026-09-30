<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover issue 07: PhpHosting surfaces use $model->project(), not connect() or Lib\Apis\System.
 */
final class PhphostingSurfacesCutoverTest extends TestCase
{
    public function test_phphosting_http_layers_do_not_use_connect_or_lib_system(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app';
        $paths = [
            'Http/Controllers/User/FileController.php',
            'Http/Controllers/User/FtpAccountController.php',
            'Http/Controllers/User/CronJobController.php',
            'Http/Controllers/User/PhpController.php',
            'Http/Controllers/User/WpCliController.php',
            'Models/FtpAccount.php',
            'Console/Commands/Apache/EnableMod.php',
            'Console/Commands/Apache/DisableMod.php',
            'Console/Commands/Domains/WpCli.php',
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
                $hits[] = $relative . ' (imports Lib System)';
            }
            if (preg_match('/->connect\\(\\)/', $contents) === 1) {
                $hits[] = $relative . ' (calls connect())';
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }

    public function test_surfaces_use_project_collaborators(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'User';

        $ftp = file_get_contents($root . DIRECTORY_SEPARATOR . 'FtpAccountController.php');
        $cron = file_get_contents($root . DIRECTORY_SEPARATOR . 'CronJobController.php');
        $files = file_get_contents($root . DIRECTORY_SEPARATOR . 'FileController.php');

        $this->assertIsString($ftp);
        $this->assertIsString($cron);
        $this->assertIsString($files);

        $this->assertStringContainsString('->project()->ftp()->', $ftp);
        $this->assertStringContainsString('->project()->cron()->', $cron);
        $this->assertStringContainsString('->project()->fileManager()', $files);
        $this->assertStringContainsString('->project()->resolvePath(', $files);
    }
}
