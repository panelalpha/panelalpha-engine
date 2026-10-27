<?php

namespace Tests\Unit\Deploy;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Detection and inspection read checkouts through AccountHomeRead, which will
 * not read a file a link carried into another account's home. Only the files
 * below still open paths themselves, and every one of them reads the engine's
 * own files: shipped resources, config, its logs, locks and spool, /proc.
 * RecipeFileOverlay reads nothing: it only creates new files (`fopen` mode `x`)
 * in a throwaway inspect clone.
 */
class CheckoutReadsTest extends TestCase
{
    private const ENGINE_FILE_READERS = [
        'CacheManager/ImageCatalog.php',
        'DeployLog/DeployLock.php',
        'DeployLog/DeployLogReader.php',
        'DeployLog/DeployStatus.php',
        'DeployLog/ProcessIdentity.php',
        'DeployLog/StepWatchdog.php',
        'Dind/DindHostBuilder.php',
        'Dind/HostBuildSlot.php',
        'Health/CheckRegistry.php',
        'Inspect/RecipeFileOverlay.php',
        'Platform/PlatformRegistry.php',
        'Platform/Runtime/Php/PhpHostBuild.php',
        'Platform/Runtime/RuntimeImageCatalog.php',
        'Platform/SourceRecipes.php',
        'Sidecar/SidecarDialects.php',
        'Telemetry/HostFacts.php',
        'Telemetry/InstallFingerprint.php',
        'Telemetry/Spool.php',
        'Telemetry/TelemetryShipper.php',
        'Template/TemplateLoader.php',
    ];

    private const OPENS_A_PATH = '/(?<![\w$>:\\\\])(?:file_get_contents|fopen|file|simplexml_load_file|parse_ini_file|readfile)\(|(?<!\w)Yaml::parseFile\(|new \\\\?SplFileObject\(/';

    public function test_only_the_engines_own_files_are_read_without_account_home_read(): void
    {
        $found = [];
        $deploy = base_path('app/Lib/Deploy');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($deploy));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($deploy) + 1);
            if ($file->isFile() && $file->getExtension() === 'php' && $relative !== 'AccountHomeRead.php'
                && preg_match(self::OPENS_A_PATH, (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = $relative;
            }
        }
        sort($found);

        $this->assertSame(self::ENGINE_FILE_READERS, $found);
    }
}
