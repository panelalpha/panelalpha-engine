<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * engine#182, the Apaxy follow-up: `check_skip` lets a manifest say a shipped
 * check does not apply, with a reason. Everything a skip could get wrong is
 * refused where it is written, never discovered as a silent health report.
 */
class ManifestCheckSkipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        SourceRecipes::flush();
    }

    /**
     * @param list<mixed> $skips
     * @param array<string, mixed> $raw
     */
    private function manifest(array $skips, array $raw = []): PlatformManifest
    {
        return PlatformManifest::fromArray(
            array_merge([
                'id' => 'probe',
                'label' => 'Probe',
                'strategy' => 'probe',
                'priority' => 0,
                'runtime' => 'node',
                'check_skip' => $skips,
            ], $raw),
            'probe.yaml',
            requireDetect: false
        );
    }

    private function refused(string $message, array $skips, array $raw = []): void
    {
        try {
            $this->manifest($skips, $raw);
        } catch (ManifestException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }
        $this->fail("check_skip was accepted, expected: {$message}");
    }

    public function test_no_key_means_no_skips(): void
    {
        $manifest = PlatformManifest::fromArray(
            ['id' => 'probe', 'label' => 'Probe', 'priority' => 0],
            'probe.yaml',
            requireDetect: false
        );

        $this->assertSame([], $manifest->checkSkips);
    }

    public function test_a_warning_check_is_skipped_with_its_reason(): void
    {
        $manifest = $this->manifest([
            ['check' => '_baseline/not-a-dev-server', 'reason' => ' The dev server is the product. '],
        ]);

        $this->assertSame(
            [['check' => '_baseline/not-a-dev-server', 'reason' => 'The dev server is the product.', 'covered_by' => null]],
            $manifest->checkSkips
        );
    }

    public function test_an_unknown_check_is_refused(): void
    {
        $this->refused("check_skip '_baseline/no-such-check': the engine ships no such check", [
            ['check' => '_baseline/no-such-check', 'reason' => 'typo'],
        ]);
    }

    public function test_a_missing_reason_is_refused(): void
    {
        $this->refused('a reason is required', [['check' => '_baseline/not-a-dev-server']]);
        $this->refused('a reason is required', [['check' => '_baseline/not-a-dev-server', 'reason' => '  ']]);
    }

    /** A whole group is too blunt to waive: each check is named, with its reason. */
    public function test_a_group_is_refused(): void
    {
        $this->refused("must name one check as 'group/id'", [['check' => '_baseline', 'reason' => 'all of it']]);
    }

    public function test_an_unknown_entry_key_is_refused(): void
    {
        $this->refused('take check, reason and covered_by only', [
            ['check' => '_baseline/not-a-dev-server', 'reason' => 'r', 'because' => 'r'],
        ]);
    }

    public function test_a_map_instead_of_a_list_is_refused(): void
    {
        $this->refused('must be a list', ['check' => '_baseline/not-a-dev-server', 'reason' => 'r']);
    }

    public function test_a_check_this_manifest_is_never_asked_is_refused(): void
    {
        // node is not asked php's checks: the skip would be dead text.
        $this->refused('this manifest is never asked that check', [
            ['check' => 'php/no-diagnostics-in-output', 'reason' => 'r'],
        ]);
    }

    public function test_the_same_check_twice_is_refused(): void
    {
        $this->refused('listed twice', [
            ['check' => '_baseline/not-a-dev-server', 'reason' => 'r'],
            ['check' => '_baseline/not-a-dev-server', 'reason' => 'r'],
        ]);
    }

    /** The rule that keeps a skip from hiding an outage. */
    public function test_an_error_check_without_a_cover_is_refused(): void
    {
        $this->refused("'_baseline/no-directory-listing' has severity error", [
            ['check' => '_baseline/no-directory-listing', 'reason' => 'the listing is the app'],
        ]);
    }

    public function test_an_error_check_covered_by_a_warning_is_refused(): void
    {
        $this->refused('has severity error', [[
            'check' => '_baseline/no-directory-listing',
            'reason' => 'r',
            'covered_by' => '_baseline/not-a-dev-server',
        ]]);
    }

    public function test_a_cover_the_manifest_is_not_asked_is_refused(): void
    {
        $this->refused("covered_by 'php/entry-served' must be another check", [[
            'check' => '_baseline/no-directory-listing',
            'reason' => 'r',
            'covered_by' => 'php/entry-served',
        ]]);
    }

    public function test_a_cover_that_is_itself_skipped_is_refused(): void
    {
        $this->refused('must be another check this manifest is asked and does not skip', [
            ['check' => '_baseline/no-directory-listing', 'reason' => 'r', 'covered_by' => '_baseline/not-placeholder'],
            ['check' => '_baseline/not-placeholder', 'reason' => 'r', 'covered_by' => '_baseline/no-server-error'],
        ]);
    }

    public function test_an_error_check_covered_by_an_error_check_is_accepted(): void
    {
        $manifest = $this->manifest([[
            'check' => '_baseline/no-directory-listing',
            'reason' => 'r',
            'covered_by' => 'node/no-stack-trace',
        ]]);

        $this->assertSame('node/no-stack-trace', $manifest->checkSkips[0]['covered_by']);
    }

    /** The shipped consumer: Apaxy covers the listing check with its own theme check. */
    public function test_the_apaxy_recipe_skips_the_listing_check_covered_by_its_own(): void
    {
        $recipe = SourceRecipes::for('https://github.com/oupala/apaxy');

        $this->assertNotNull($recipe);
        $this->assertSame('_baseline/no-directory-listing', $recipe->checkSkips[0]['check'] ?? null);
        $this->assertSame('_baseline/apaxy-theme-applied', $recipe->checkSkips[0]['covered_by'] ?? null);
    }
}
