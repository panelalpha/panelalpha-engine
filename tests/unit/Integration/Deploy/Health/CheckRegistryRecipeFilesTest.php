<?php

namespace Tests\Integration\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\CheckRegistry;
use PHPUnit\Framework\TestCase;

/**
 * How a recipe's checks directory is read: what is skipped, what a whole-group reference answers to, and the
 * message each malformed file fails with.
 */
class CheckRegistryRecipeFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        $this->dir = sys_get_temp_dir().'/pa-recipe-files-'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/checks', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        CheckRegistry::flush();
        parent::tearDown();
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir.'/checks/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    private function check(string $id): string
    {
        return "id: {$id}\nexpect:\n  status: [200]\nmessage: 'Not a 200.'\n";
    }

    public function test_the_shipped_directory_yields_only_its_group_folders(): void
    {
        $groups = CheckRegistry::all();

        $this->assertArrayHasKey('_baseline', $groups);
        $this->assertArrayNotHasKey('_schema.json', $groups);
        $this->assertSame(array_values(array_filter(array_keys($groups), static fn (string $g): bool => ! str_starts_with($g, '.'))), array_keys($groups));
    }

    public function test_stray_files_hidden_folders_and_empty_groups_are_not_groups(): void
    {
        $this->write('readme.txt', 'not a group');
        $this->write('.cache/stale.yaml', $this->check('stale'));
        $this->write('empty/notes.md', 'nothing to load');
        $this->write('shop/cart-works.yaml', $this->check('cart-works'));

        $groups = CheckRegistry::allWithDirectory($this->dir.'/checks');

        $this->assertSame(['shop/cart-works'], array_map(static fn ($c): string => $c->reference(), $groups['shop']));
        $this->assertArrayNotHasKey('empty', $groups);
        $this->assertArrayNotHasKey('.cache', $groups);
        $this->assertArrayNotHasKey('readme.txt', $groups);
    }

    public function test_a_whole_recipe_group_is_answered_for_and_an_unknown_group_is_not(): void
    {
        $this->write('shop/cart-works.yaml', $this->check('cart-works'));

        $this->assertSame(
            ['shop', 'shop/cart-works'],
            CheckRegistry::recipeReferences($this->dir.'/checks', ['shop', 'shop/cart-works', 'blog', 'shop/missing'])
        );
    }

    public function test_a_file_that_is_not_yaml_names_itself(): void
    {
        $this->write('shop/broken.yaml', "id: [unclosed\n");

        $this->expectException(CheckException::class);
        $this->expectExceptionMessageMatches('#^recipe shop/broken\.yaml: #');

        CheckRegistry::allWithDirectory($this->dir.'/checks');
    }

    public function test_a_file_that_is_not_a_mapping_is_refused(): void
    {
        $this->write('shop/scalar.yaml', "just a sentence\n");

        $this->expectExceptionObject(new CheckException('recipe shop/scalar.yaml: expected a mapping'));

        CheckRegistry::allWithDirectory($this->dir.'/checks');
    }

    public function test_a_file_must_be_named_for_its_id(): void
    {
        $this->write('shop/other-name.yaml', $this->check('cart-works'));

        $this->expectExceptionObject(new CheckException('recipe shop/other-name.yaml: file should be named cart-works.yaml'));

        CheckRegistry::allWithDirectory($this->dir.'/checks');
    }
}
