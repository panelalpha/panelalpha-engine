<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use RuntimeException;

/**
 * The plugin zip a run downloads lives in core's temp directory only until
 * that run ends: completed, failed or cut short by a cancel. A later run of
 * the same import in the same worker downloads it again.
 */
class WordpressMigrationZipCleanupTest extends WordpressMigrationTestCase
{
    public function test_a_run_that_fails_after_the_download_leaves_no_temp_directory(): void
    {
        $zip = $this->countingZip();
        $migration = $this->migration();
        $paths = [];

        try {
            $this->runOnce($migration, $this->downloadingStep($zip, $paths, new RuntimeException('staging failed')));
            $this->fail('Expected the step to fail');
        } catch (RuntimeException) {
        }

        $this->assertCount(1, $paths);
        $this->assertDirectoryDoesNotExist(dirname($paths[0]));
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->fresh()->status);
    }

    public function test_each_run_downloads_its_own_zip_and_removes_it_when_it_ends(): void
    {
        $zip = $this->countingZip();
        $migration = $this->migration();
        $paths = [];
        $step = $this->downloadingStep($zip, $paths);

        $this->runOnce($migration, $step);
        $this->runOnce($migration->fresh(), $step);

        $this->assertSame(2, $zip->downloads);
        $this->assertNotSame($paths[0], $paths[1]);
        $this->assertDirectoryDoesNotExist(dirname($paths[0]));
        $this->assertDirectoryDoesNotExist(dirname($paths[1]));
    }

    public function test_a_cached_zip_whose_file_is_gone_is_downloaded_again(): void
    {
        $zip = $this->countingZip();
        $first = $zip->installZipFor(7);
        unlink($first);

        $second = $zip->installZipFor(7);

        $this->assertSame(2, $zip->downloads);
        $this->assertFileExists($second);
        WordpressMigratorZip::discard(7);
        $this->assertDirectoryDoesNotExist(dirname($first));
        $this->assertDirectoryDoesNotExist(dirname($second));
    }

    private function migration(): WordpressMigration
    {
        return WordpressMigration::start('https://example.com', 'admin', 'secret');
    }

    private function runOnce(WordpressMigration $migration, MigrationStep $step): void
    {
        $sequence = [];
        foreach (WordPressMigrationModule::stepNames() as $name) {
            $sequence[] = $name === WordpressMigration::STEP_RESOLVE_SOURCE ? $step : new NoOpStep($name);
        }

        (new WordPressMigrationModule($sequence, new NoFailureCleanup))->advanceOnce($migration);
    }

    /**
     * Resolve-source stand-in that downloads the zip, as the plugin steps do,
     * and then fails or stays pending.
     *
     * @param  list<string>  $paths
     */
    private function downloadingStep(WordpressMigratorZip $zip, array &$paths, ?RuntimeException $failure = null): MigrationStep
    {
        return new class($zip, $paths, $failure) implements BatchedStep
        {
            /** @var list<string> */
            private array $paths;

            /**
             * @param  list<string>  $paths
             */
            public function __construct(private WordpressMigratorZip $zip, array &$paths, private ?RuntimeException $failure)
            {
                $this->paths = &$paths;
            }

            public function name(): string
            {
                return WordpressMigration::STEP_RESOLVE_SOURCE;
            }

            public function run(WordpressMigration $migration): void
            {
                $this->paths[] = $this->zip->installZipFor($migration->id);
                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
    }

    private function countingZip(): WordpressMigratorZip
    {
        return new class extends WordpressMigratorZip
        {
            public int $downloads = 0;

            public function downloadAndRepack(?string $releaseUrl = null): string
            {
                $this->downloads++;
                $dir = sys_get_temp_dir().'/wp-migrator-'.bin2hex(random_bytes(8));
                mkdir($dir, 0700, true);
                file_put_contents($dir.'/wordpress-migrator-install.zip', 'zip');

                return $dir.'/wordpress-migrator-install.zip';
            }
        };
    }
}
