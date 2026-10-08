<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * A `check:` reference is validated where it is written, not where it is used.
 *
 * Nothing used to check it, and the failure mode was silent in the worst
 * possible direction. `CheckRegistry::resolve()` throws on a reference no
 * check answers to, `CheckRunner::for()` propagates that, and
 * `AppHealth::runChecks()` catches `Throwable` and returns `serving: unknown,
 * checks: []` — so one typo in a recipe deleted the entire health section of
 * every deploy of that application, and a deploy with no failed checks read as
 * a healthy one.
 *
 * A source recipe is where this matters most: an operator editing
 * `.panelalpha/panelalpha.yaml` is one keystroke from disabling every check on
 * their project and had no way to tell.
 */
class ManifestCheckReferenceTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-manifest-checks-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($this->dir . '/' . $entry)) {
                unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray(
            // A real recipe's shape, `priority` included: it is required, and a
            // fixture that omits it fails before reaching the check rules.
            array_merge([
                'id' => 'probe',
                'label' => 'Probe',
                'strategy' => 'probe',
                'priority' => 0,
            ], $raw),
            'probe.yaml',
            requireDetect: false
        );
    }

    public function test_a_shipped_check_reference_resolves(): void
    {
        $manifest = $this->manifest(['check' => ['_baseline/not-placeholder']]);

        $this->assertSame(['_baseline/not-placeholder'], $manifest->checks);
    }

    public function test_a_whole_group_reference_resolves(): void
    {
        $manifest = $this->manifest(['check' => ['node']]);

        $this->assertSame(['node'], $manifest->checks);
    }

    /** The regression this exists for: a typo is refused, not silently fatal. */
    public function test_a_typo_in_a_check_id_is_refused_at_load(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("group 'node' has no check 'no-missing-root-route-typo'");

        $this->manifest(['check' => ['node/no-missing-root-route-typo']]);
    }

    public function test_a_typo_in_a_group_name_is_refused_and_names_the_groups(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("no group 'basline'");

        try {
            $this->manifest(['check' => ['basline/no-server-error']]);
        } catch (ManifestException $e) {
            // The message has to be actionable on its own: the author has a
            // typo and no way to enumerate the groups from the tree.
            $this->assertStringContainsString('_baseline', $e->getMessage());
            $this->assertStringContainsString('node', $e->getMessage());

            throw $e;
        }
    }

    public function test_a_malformed_reference_is_still_refused(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'group' or 'group/id'");

        $this->manifest(['check' => ['Not A Reference']]);
    }

    public function test_duplicate_references_are_still_collapsed(): void
    {
        $manifest = $this->manifest(['check' => ['node/no-stack-trace', 'node/no-stack-trace']]);

        $this->assertSame(['node/no-stack-trace'], $manifest->checks);
    }

    /**
     * A recipe's own check is not in `resources/checks/`, so the reference has
     * to be answered by the recipe directory before it is refused as unknown.
     * Both halves matter: refused without the directory, accepted with it.
     */
    public function test_a_recipe_check_reference_is_answered_by_its_directory(): void
    {
        $recipe = $this->dir . '/recipe';
        mkdir($recipe . '/checks/_baseline', 0777, true);
        file_put_contents(
            $recipe . '/checks/_baseline/api-health.yaml',
            "id: api-health\nmessage: 'x'\nexpect:\n  status: [200]\nfix: 'y'\n"
        );

        // Without the directory it is an unknown check, and saying so is the
        // whole point of validating references at load.
        try {
            $this->manifest(['check' => ['_baseline/api-health']]);
            $this->fail('A recipe check reference was accepted with no recipe directory');
        } catch (ManifestException $e) {
            $this->assertStringContainsString("has no check 'api-health'", $e->getMessage());
        }

        $manifest = PlatformManifest::fromArray(
            [
                'id' => 'probe',
                'label' => 'Probe',
                'strategy' => 'probe',
                'priority' => 0,
                'check' => ['_baseline/api-health'],
            ],
            'probe.yaml',
            requireDetect: false,
            recipeChecks: SourceRecipes::checksDirectory($recipe)
        );

        $this->assertSame(['_baseline/api-health'], $manifest->checks);
    }

    public function test_recipe_checks_directory_is_found_beside_the_manifest(): void
    {
        $recipe = $this->dir . '/recipe';
        mkdir($recipe . '/checks/_baseline', 0777, true);
        file_put_contents(
            $recipe . '/checks/_baseline/api-health.yaml',
            "id: api-health\nmessage: 'x'\nexpect:\n  status: [200]\nfix: 'y'\n"
        );

        $this->assertSame($recipe . '/checks', SourceRecipes::checksDirectory($recipe));

        // A recipe with no checks of its own answers null, so the health check
        // reads the shipped tree exactly as it did before this existed.
        mkdir($this->dir . '/bare', 0777, true);
        $this->assertNull(SourceRecipes::checksDirectory($this->dir . '/bare'));
    }
}
