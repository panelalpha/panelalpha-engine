<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * engine#169: a queue worker started before a recipe existed.
 *
 * `for()` read the new directory and detection named the recipe, while
 * `findById()` walked a list cached at worker start and returned null, so
 * EntrypointWriter wrote no entrypoint and every stage command was dropped.
 * No flush() here on purpose: a worker never calls it.
 */
class SourceRecipesFreshnessTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/source-recipes-fresh-' . bin2hex(random_bytes(8));
        $this->recipe('github.com/acme/old', "id: acme-old\nlabel: Old\nstrategy: static\n");
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        SourceRecipes::flush();
        parent::tearDown();
    }

    public function test_a_recipe_added_after_the_walk_is_found_by_id(): void
    {
        $this->assertNull(SourceRecipes::findById('acme-new', $this->root));

        $this->recipe('github.com/acme/new', "id: acme-new\nlabel: New\nstrategy: static\n");

        $this->assertSame('acme-new', SourceRecipes::for('https://github.com/acme/new', $this->root)?->id);
        $this->assertSame('acme-new', SourceRecipes::findById('acme-new', $this->root)?->id);
    }

    public function test_a_directory_whose_manifest_arrives_later_is_picked_up(): void
    {
        $dir = $this->recipe('github.com/acme/late', null);
        $this->assertNull(SourceRecipes::findById('acme-late', $this->root));

        file_put_contents($dir . '/panelalpha.yaml', "id: acme-late\nlabel: Late\nstrategy: static\n");

        $this->assertSame('acme-late', SourceRecipes::findById('acme-late', $this->root)?->id);
    }

    public function test_an_edited_recipe_is_read_again_by_both_lookups(): void
    {
        $this->assertSame('Old', SourceRecipes::for('https://github.com/acme/old', $this->root)?->label);
        $this->assertSame('Old', SourceRecipes::findById('acme-old', $this->root)?->label);

        file_put_contents(
            $this->root . '/github.com/acme/old/panelalpha.yaml',
            "id: acme-old\nlabel: Renamed\nstrategy: static\n"
        );

        $this->assertSame('Renamed', SourceRecipes::for('https://github.com/acme/old', $this->root)?->label);
        $this->assertSame('Renamed', SourceRecipes::findById('acme-old', $this->root)?->label);
    }

    /** Nothing changed: the same objects come back, the tree is not parsed again. */
    public function test_an_unchanged_tree_is_served_from_the_cache(): void
    {
        $first = SourceRecipes::all($this->root);

        $this->assertSame($first, SourceRecipes::all($this->root));
    }

    private function recipe(string $slug, ?string $yaml): string
    {
        $dir = $this->root . '/' . $slug;
        mkdir($dir, 0777, true);
        if ($yaml !== null) {
            file_put_contents($dir . '/panelalpha.yaml', $yaml);
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
