<?php

namespace Tests\Unit\System;

use PHPUnit\Framework\TestCase;

/**
 * Production cutover: project settings callers use App\System\Project\Settings,
 * not App\Lib\Project\ProjectSettings.
 */
final class ProjectSettingsCutoverTest extends TestCase
{
    public function test_lib_project_settings_file_is_gone(): void
    {
        $path = dirname(__DIR__, 4) . '/core/app/Lib/Project/ProjectSettings.php';
        $this->assertFileDoesNotExist($path);
    }

    public function test_settings_entry_layers_do_not_import_lib_project_settings(): void
    {
        $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app';
        $paths = [
            'Http/Controllers/User/ProjectSettingController.php',
            'Console/Commands/Users/ProjectSettings/ListCommand.php',
            'Console/Commands/Users/ProjectSettings/Get.php',
            'Console/Commands/Users/ProjectSettings/Set.php',
            'Console/Commands/Users/ProjectSettings/UnsetCommand.php',
        ];

        $hits = [];
        foreach ($paths as $relative) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $contents = file_get_contents($file);
            if ($contents === false) {
                $hits[] = $relative . ' (unreadable)';
                continue;
            }
            if (str_contains($contents, 'App\\Lib\\Project\\ProjectSettings')) {
                $hits[] = $relative;
            }
        }

        $this->assertSame([], $hits, implode("\n", $hits));
    }
}
