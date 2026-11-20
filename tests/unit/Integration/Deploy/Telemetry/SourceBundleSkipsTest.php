<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\SourceBundle;
use PHPUnit\Framework\TestCase;

/**
 * Each reason create() gives for not producing a bundle, and that it leaves no archive behind when it gives one.
 */
class SourceBundleSkipsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not installed');
        }
        $this->dir = sys_get_temp_dir().'/pa-bundle-skip-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/project', 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->rmrf($this->dir);
        }
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmrf($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir.'/project/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    public function test_an_archive_path_that_cannot_be_opened_is_an_error(): void
    {
        $this->write('index.php', '<?php echo 1;');
        mkdir($this->dir.'/out.zip');

        $result = SourceBundle::create($this->dir.'/project', $this->dir.'/out.zip', 1048576, 100, 65536);

        $this->assertSame(['available' => false, 'skipped' => SourceBundle::SKIP_ERROR], $result);
    }

    public function test_an_archive_that_comes_out_over_the_budget_is_deleted(): void
    {
        $this->write('blob.txt', random_bytes(200));
        $zipPath = $this->dir.'/out.zip';

        $result = SourceBundle::create($this->dir.'/project', $zipPath, 200, 100, 65536);

        $this->assertSame(['available' => false, 'skipped' => SourceBundle::SKIP_TOO_LARGE], $result);
        $this->assertFileDoesNotExist($zipPath);
    }

    public function test_a_tree_over_the_file_count_writes_no_archive(): void
    {
        $this->write('a.txt', 'a');
        $this->write('b.txt', 'b');
        $zipPath = $this->dir.'/out.zip';

        $result = SourceBundle::create($this->dir.'/project', $zipPath, 1048576, 1, 65536);

        $this->assertSame(['available' => false, 'skipped' => SourceBundle::SKIP_TOO_MANY_FILES], $result);
        $this->assertFileDoesNotExist($zipPath);
    }

    public function test_a_special_file_is_never_bundled(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo is not available');
        }
        $this->write('index.html', '<h1>hi</h1>');
        posix_mkfifo($this->dir.'/project/pipe', 0600);

        $plan = SourceBundle::plan($this->dir.'/project', 1048576, 100, 65536);

        $this->assertSame(['index.html'], array_keys($plan['files']));
    }
}
