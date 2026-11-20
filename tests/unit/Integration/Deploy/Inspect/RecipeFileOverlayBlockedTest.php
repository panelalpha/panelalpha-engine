<?php

namespace Tests\Integration\Deploy\Inspect;

use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use Tests\TestCase;

/**
 * An app config's file is skipped, never forced, when the clone is gone or a file of the repository is in its way.
 */
class RecipeFileOverlayBlockedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-overlay-blocked-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/.panelalpha/files/sub', 0755, true);
        file_put_contents($this->dir.'/.panelalpha/panelalpha.yaml', "description: x\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_file_whose_folder_is_a_file_in_the_repository_is_skipped(): void
    {
        file_put_contents($this->dir.'/.panelalpha/files/sub/settings.ini', "debug=0\n");
        file_put_contents($this->dir.'/.panelalpha/files/top.ini', "a=1\n");
        file_put_contents($this->dir.'/sub', 'the repository has a file here');

        $written = RecipeFileOverlay::apply($this->dir, null);

        $this->assertSame(['top.ini'], $written);
        $this->assertSame('the repository has a file here', file_get_contents($this->dir.'/sub'));
    }

    public function test_a_file_whose_place_is_a_folder_in_the_repository_is_skipped(): void
    {
        file_put_contents($this->dir.'/.panelalpha/files/conf', "a=1\n");
        mkdir($this->dir.'/conf');

        $this->assertSame([], RecipeFileOverlay::apply($this->dir, null));
        $this->assertDirectoryExists($this->dir.'/conf');
    }

    public function test_a_clone_that_is_gone_gets_nothing(): void
    {
        $this->assertSame([], RecipeFileOverlay::apply($this->dir.'/gone', 'https://github.com/KL-Psychological-Methodology/ESMira'));
    }
}
