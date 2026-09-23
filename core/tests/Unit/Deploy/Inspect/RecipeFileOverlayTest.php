<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use Tests\TestCase;

/**
 * engine#270: the deploy lays a recipe's files over the checkout before
 * detection; inspect read the bare clone and called ESMira undeployable.
 */
class RecipeFileOverlayTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-overlay-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_recipe_supplies_the_files_the_repository_lacks(): void
    {
        $written = RecipeFileOverlay::apply($this->dir, 'https://github.com/KL-Psychological-Methodology/ESMira');

        $this->assertContains('package.json', $written);
        $this->assertFileExists($this->dir . '/package.json');
    }

    public function test_a_repository_with_no_recipe_is_left_as_it_is(): void
    {
        file_put_contents($this->dir . '/index.php', '<?php');

        $this->assertSame([], RecipeFileOverlay::apply($this->dir, 'https://github.com/nobody/no-recipe-here'));
        $this->assertSame(['index.php'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }
}
