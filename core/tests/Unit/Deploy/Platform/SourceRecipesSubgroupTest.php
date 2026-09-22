<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * A recipe may sit under a GitLab subgroup, so its directory is deeper than
 * `<host>/<owner>/<repo>`. The walk must reach it, still find the ordinary
 * three-level recipes, and not treat the namespace directory above a subgroup
 * repo as a manifest-less recipe of its own.
 */
class SourceRecipesSubgroupTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/source-recipes-sub-' . bin2hex(random_bytes(8));
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        SourceRecipes::flush();
        parent::tearDown();
    }

    private function recipe(string $slug, ?string $yaml): void
    {
        $dir = $this->root . '/' . $slug;
        mkdir($dir, 0777, true);
        if ($yaml !== null) {
            file_put_contents($dir . '/panelalpha.yaml', $yaml);
        }
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

    /**
     * A four-level subgroup recipe and a plain three-level one are both found,
     * each keyed by the slug its path spells; the namespace directory in
     * between carries no manifest and is walked through, not reported.
     */
    public function test_a_subgroup_recipe_and_a_plain_recipe_are_both_found(): void
    {
        $this->recipe('github.com/acme/good', "id: acme-good\nlabel: Good\nstrategy: static\n");
        $this->recipe('gitlab.com/rtraceio/web/flink', "id: flink\nlabel: Flink\nstrategy: static\n");
        SourceRecipes::flush();

        $dirs = SourceRecipes::directories($this->root);

        $this->assertArrayHasKey('github.com/acme/good', $dirs);
        $this->assertArrayHasKey('gitlab.com/rtraceio/web/flink', $dirs);
        // The namespace directory above the subgroup repo is not itself a recipe.
        $this->assertArrayNotHasKey('gitlab.com/rtraceio/web', $dirs);

        $ids = array_map(static fn ($m) => $m->id, SourceRecipes::all($this->root));
        sort($ids);
        $this->assertSame(['acme-good', 'flink'], $ids);

        // Walking a namespace directory must not manufacture a failure.
        $this->assertSame([], SourceRecipes::skipped($this->root));
    }

    /**
     * A recipe's own nested `files/…/panelalpha.yaml` payload sits below the
     * recipe's manifest and must not be picked up as a second recipe.
     */
    public function test_a_nested_payload_manifest_is_not_a_second_recipe(): void
    {
        $this->recipe('github.com/acme/good', "id: acme-good\nlabel: Good\nstrategy: static\n");
        // A file the recipe ships that happens to be named like the manifest.
        $payload = $this->root . '/github.com/acme/good/files/config';
        mkdir($payload, 0777, true);
        file_put_contents($payload . '/panelalpha.yaml', "irrelevant: true\n");
        SourceRecipes::flush();

        $dirs = SourceRecipes::directories($this->root);

        $this->assertSame(['github.com/acme/good'], array_keys($dirs));
    }
}
