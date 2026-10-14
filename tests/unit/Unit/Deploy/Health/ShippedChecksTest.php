<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\ExplainerRegistry;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The contract the shipped check files have to keep.
 *
 * Auto-inclusion is what makes this test necessary rather than tidy. A group
 * is picked up by every manifest with that runtime -- nine for nginx, fifteen
 * for php -- so a check with a typo in its explainer name, or one whose id
 * does not match its filename, is silently wrong in nine places and shows up
 * as a health report that quietly asks one question fewer than it claims.
 */
class ShippedChecksTest extends TestCase
{
    /** @return list<HealthCheck> */
    private function checks(): array
    {
        $all = [];
        foreach (CheckRegistry::all() as $group) {
            foreach ($group as $check) {
                $all[] = $check;
            }
        }

        return $all;
    }

    public function test_every_shipped_check_parses(): void
    {
        $this->assertNotEmpty($this->checks());
    }

    /** The group every application is asked, whatever it runs. */
    public function test_the_baseline_group_exists_and_is_not_empty(): void
    {
        $groups = CheckRegistry::all();

        $this->assertArrayHasKey(CheckRegistry::BASELINE, $groups);
        $this->assertNotEmpty($groups[CheckRegistry::BASELINE]);
    }

    /**
     * A group is picked up from a manifest's `runtime`, so a directory named
     * for something that is not a runtime is a group no manifest will ever
     * be given.
     */
    public function test_every_group_is_the_baseline_or_a_runtime(): void
    {
        foreach (array_keys(CheckRegistry::all()) as $group) {
            if ($group === CheckRegistry::BASELINE) {
                continue;
            }
            $this->assertTrue(
                PlatformManifest::isRuntime($group),
                "Check group '{$group}' is neither the baseline nor a runtime, so nothing selects it"
            );
        }
    }

    public function test_every_explainer_a_check_names_exists(): void
    {
        foreach ($this->checks() as $check) {
            if ($check->explain === null) {
                continue;
            }
            $this->assertNotNull(
                ExplainerRegistry::find($check->explain),
                "{$check->reference()} names explainer '{$check->explain}', which no class provides"
            );
        }
    }

    /**
     * The report names a check by id and a person then goes looking for it.
     * Two groups may each have a `no-server-error`; one group may not.
     */
    public function test_check_ids_are_unique_within_their_group(): void
    {
        foreach (CheckRegistry::all() as $name => $group) {
            $ids = array_map(static fn (HealthCheck $c): string => $c->id, $group);
            $this->assertSame(array_unique($ids), $ids, "Duplicate check id in group '{$name}'");
        }
    }

    /** A check nobody can act on is a line of noise in a deploy log. */
    public function test_every_check_says_what_is_wrong(): void
    {
        foreach ($this->checks() as $check) {
            $this->assertNotSame('', trim($check->message), "{$check->reference()} has no message");
        }
    }

    /**
     * `fix` or `explain`: a report that says what broke and not what to do
     * about it is a support ticket rather than an answer.
     */
    public function test_every_check_offers_a_way_forward(): void
    {
        foreach ($this->checks() as $check) {
            $this->assertTrue(
                $check->fix !== null || $check->explain !== null,
                "{$check->reference()} has neither a 'fix' nor an 'explain'"
            );
        }
    }

    /**
     * A manifest adds to its runtime's group; it never names the group it was
     * already given. Fifteen manifests carrying `check: [php]` is the
     * duplication the groups exist to remove, moved up one level.
     */
    public function test_no_manifest_lists_its_own_runtime_group(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            $this->assertNotContains(
                $manifest->runtime,
                $manifest->checks,
                "{$manifest->id} lists its own runtime group '{$manifest->runtime}'; it is included already"
            );
        }
    }

    /** Every reference a manifest makes resolves, or it silently never runs. */
    public function test_every_manifest_check_reference_resolves(): void
    {
        foreach (PlatformRegistry::all() as $manifest) {
            CheckRegistry::forManifest($manifest);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The groups that exist, named rather than assumed. Meant to be edited:
     * adding a directory should make this fail, and the edit is the record
     * that the group arrived.
     *
     * `compose` and `dockerfile` are deliberately absent, and it is the same
     * fact twice: they are the two runtimes whose recipe the engine did not
     * write, so there is nothing about the response that only they could
     * produce. Everything that breaks them -- a stock server page, a
     * directory listing, a dev server, an unreachable sidecar -- is evidence
     * that reads identically whoever built the image, and lives in the
     * baseline where every runtime is asked it.
     */
    public function test_the_groups_that_exist_are_the_ones_built_so_far(): void
    {
        $groups = array_keys(CheckRegistry::all());
        sort($groups);

        $this->assertSame([
            CheckRegistry::BASELINE,
            PlatformManifest::RUNTIME_COMMAND,
            PlatformManifest::RUNTIME_NGINX,
            PlatformManifest::RUNTIME_NODE,
            PlatformManifest::RUNTIME_PHP,
        ], $groups);
    }

    /**
     * A runtime with no group of its own is still asked something. The
     * baseline is what makes "no group" a statement about this runtime having
     * nothing of its own to ask, rather than an application nobody checks.
     */
    public function test_every_runtime_is_asked_something(): void
    {
        foreach (PlatformManifest::runtimes() as $runtime) {
            $this->assertNotEmpty(
                CheckRegistry::for($runtime),
                "Nothing at all is asked of a {$runtime} application"
            );
        }
    }
}
