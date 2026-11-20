<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * Lookups that need no recipe tree: an empty id, and a tree that is not there.
 */
class SourceRecipesLookupTest extends TestCase
{
    protected function tearDown(): void
    {
        SourceRecipes::flush();
        parent::tearDown();
    }

    public function test_an_empty_id_names_no_recipe(): void
    {
        $this->assertNull(SourceRecipes::findById(''));
    }

    public function test_a_tree_that_is_not_there_has_no_recipes_and_skipped_none(): void
    {
        $missing = '/nonexistent/panelalpha-recipes-'.bin2hex(random_bytes(4));

        $this->assertSame([], SourceRecipes::skipped($missing));
        $this->assertSame([], SourceRecipes::directories($missing));
        $this->assertSame([], SourceRecipes::all($missing));
        $this->assertNull(SourceRecipes::findById('acme-good', $missing));
    }

    public function test_every_shipped_recipe_reads_without_the_tree_being_listed_first(): void
    {
        SourceRecipes::flush();

        $this->assertSame([], SourceRecipes::skipped());
        $this->assertNotSame([], SourceRecipes::directories());
    }
}
