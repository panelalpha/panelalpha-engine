<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * One unreadable recipe directory must not take the registry down with it.
 *
 * `all()` stands behind `findById()`, which stands behind `PlatformRegistry`,
 * which every deploy and every health report goes through. A directory with
 * no `panelalpha.yaml` made `at()` throw, and `all()` propagated it — so the
 * whole host failed, naming an application nobody had asked about.
 *
 * Twice in one day, both by accident: a rejection write-up committed as a
 * README with no manifest, and the window between `mkdir` and writing the
 * manifest while a recipe was being written. The second is the one that
 * matters — editing a recipe on a live host should not be an outage.
 */
class SourceRecipesResilienceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/source-recipes-' . bin2hex(random_bytes(8));
        $this->recipe('github.com/acme/good', "id: acme-good\nlabel: Good\nstrategy: static\n");
        SourceRecipes::flush();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        SourceRecipes::flush();
        parent::tearDown();
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

    /** The half-written directory: created, manifest not yet saved. */
    public function test_a_directory_with_no_manifest_does_not_break_the_others(): void
    {
        $this->recipe('github.com/knrdl/hubleys-dashboard', null);
        SourceRecipes::flush();

        $ids = array_map(static fn ($m) => $m->id, SourceRecipes::all($this->root));

        $this->assertSame(['acme-good'], $ids);
    }

    /** Skipped, not swallowed. */
    public function test_the_reason_is_recorded(): void
    {
        $this->recipe('github.com/acme/csa-admin', null);
        SourceRecipes::flush();
        SourceRecipes::all($this->root);

        $skipped = SourceRecipes::skipped($this->root);

        $this->assertArrayHasKey('github.com/acme/csa-admin', $skipped);
        $this->assertStringContainsString('panelalpha.yaml', $skipped['github.com/acme/csa-admin']);
        $this->assertArrayNotHasKey('github.com/acme/good', $skipped);
    }

    /** A manifest that is there but wrong is skipped the same way. */
    public function test_a_malformed_manifest_is_skipped_too(): void
    {
        $this->recipe('github.com/acme/broken', "id: acme-broken\nlabel: Broken\nstrategy: static\ndetect:\n  - files: [x]\n");
        SourceRecipes::flush();

        $ids = array_map(static fn ($m) => $m->id, SourceRecipes::all($this->root));

        $this->assertSame(['acme-good'], $ids);
        $this->assertArrayHasKey('github.com/acme/broken', SourceRecipes::skipped($this->root));
    }

    /**
     * The half of the old behaviour worth keeping: asking about *that*
     * repository still fails loudly, so a typo is not hidden.
     */
    public function test_deploying_the_malformed_app_itself_still_fails(): void
    {
        $dir = $this->recipe('github.com/acme/empty', null);

        $this->expectException(ManifestException::class);
        SourceRecipes::at($dir, 'github.com/acme/empty');
    }

    /** And a directory nobody has written about is still simply absent. */
    public function test_an_unknown_repository_is_still_null(): void
    {
        $this->assertNull(SourceRecipes::at($this->root . '/github.com/acme/nothing-here'));
    }
}
