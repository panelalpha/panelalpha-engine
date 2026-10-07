<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * The Galette recipe is found by the repository, not by the ref, and Galette
 * keeps composer.json in galette/ up to the 1.2 tags but at the repository root
 * from develop on. Both layouts have to end up with galette/composer.json, since
 * app_root is galette for both.
 */
class GaletteSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/galette/galette';

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/galette-recipe-' . bin2hex(random_bytes(4));
        mkdir($this->home . '/project/galette/panelalpha', 0777, true);
        mkdir($this->home . '/project/bin', 0777, true);
        file_put_contents($this->home . '/project/bin/console', "<?php // upstream console\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->home));
    }

    public function test_the_recipe_keeps_app_root_and_builds_assets_with_galettes_own_steps(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('galette', $recipe->appRoot);
        $this->assertSame('webroot', $recipe->docroot);
        $this->assertSame('npm run fomantic-install && npx gulp', $recipe->frontendBuild);
        // package.json is each ref's own, so its lockfile keeps matching.
        $this->assertFileDoesNotExist($this->recipeDir() . '/files/package.json');
    }

    public function test_a_root_composer_json_is_rebased_into_galette(): void
    {
        $root = [
            'name' => 'galette/galette',
            'autoload' => [
                'files' => ['galette/includes/functions.inc.php'],
                'psr-4' => ['Galette\\' => 'galette/lib/Galette'],
            ],
            'require' => ['php' => '>=8.3', 'slim/slim' => '^4'],
            'config' => ['vendor-dir' => 'galette/vendor/'],
        ];
        file_put_contents($this->home . '/project/composer.json', json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($this->home . '/project/composer.lock', '{"content-hash": "abc"}');

        $this->runPrepare();

        $rebased = json_decode((string) file_get_contents($this->home . '/project/galette/composer.json'), true);
        $this->assertSame('galette/galette', $rebased['name']);
        $this->assertSame(['includes/functions.inc.php'], $rebased['autoload']['files']);
        $this->assertSame(['Galette\\' => 'lib/Galette'], $rebased['autoload']['psr-4']);
        $this->assertSame('vendor/', $rebased['config']['vendor-dir']);
        $this->assertSame($root['require'], $rebased['require']);
        $this->assertSame('{"content-hash": "abc"}', file_get_contents($this->home . '/project/galette/composer.lock'));
        $this->assertFileExists($this->home . '/project/galette/panelalpha/console');
    }

    public function test_a_galette_composer_json_is_left_alone(): void
    {
        $own = '{"name": "galette/galette", "autoload": {"psr-4": {"Galette\\\\": "lib/Galette"}}}';
        file_put_contents($this->home . '/project/galette/composer.json', $own);

        $this->runPrepare();

        $this->assertSame($own, file_get_contents($this->home . '/project/galette/composer.json'));
        $this->assertFileDoesNotExist($this->home . '/project/galette/composer.lock');
        $this->assertFileExists($this->home . '/project/galette/panelalpha/console');
    }

    private function runPrepare(): void
    {
        $hook = $this->recipeDir() . '/hooks/prepare.sh';
        exec('HOME=' . escapeshellarg($this->home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }

    private function recipeDir(): string
    {
        return SourceRecipes::defaultDirectory() . '/github.com/galette/galette';
    }
}
