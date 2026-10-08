<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckException;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;

/**
 * Checks a source recipe ships beside its manifest.
 *
 * A recipe is the only part of the engine a person can change without a
 * release, and a check that knows *this application* is the natural companion
 * to the manifest that knows how to build it — the recipe that decides a
 * document root is also what knows the health endpoint. Everything here is
 * about the second directory being read the same way the first one is, and
 * about a malformed one failing where it lives rather than deleting a section
 * of every deploy's health report.
 */
class RecipeChecksTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        $this->dir = sys_get_temp_dir() . '/pa-recipe-checks-' . bin2hex(random_bytes(8));
        mkdir($this->dir . '/checks/_baseline', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        CheckRegistry::flush();
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir . '/' . $relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    private function baselineCheck(string $id = 'api-health'): string
    {
        return <<<YAML
        id: {$id}
        severity: error
        serving: database_error
        expect:
          path: /api/health
          json:
            database: connected
        message: 'The application is running but cannot reach its database.'
        fix: 'Check the database service and the credentials it is given.'
        YAML;
    }

    public function test_a_recipe_check_is_loaded_and_asked(): void
    {
        $this->write('checks/_baseline/api-health.yaml', $this->baselineCheck());

        $checks = CheckRegistry::allWithDirectory($this->dir . '/checks');

        $this->assertArrayHasKey('_baseline', $checks);
        $references = array_map(static fn ($c): string => $c->reference(), $checks['_baseline']);
        $this->assertContains('_baseline/api-health', $references);
    }

    /** Merged, not replaced: the shipped baseline checks are still asked. */
    public function test_a_recipe_adds_to_a_group_rather_than_replacing_it(): void
    {
        $this->write('checks/_baseline/api-health.yaml', $this->baselineCheck());

        $references = array_map(
            static fn ($c): string => $c->reference(),
            CheckRegistry::allWithDirectory($this->dir . '/checks')['_baseline'] ?? []
        );

        $this->assertContains('_baseline/api-health', $references);
        $this->assertContains('_baseline/not-placeholder', $references);
        $this->assertContains('_baseline/no-server-error', $references);
    }

    /** No directory is the same answer as before this existed. */
    public function test_a_recipe_without_checks_changes_nothing(): void
    {
        $this->assertSame(
            CheckRegistry::all(),
            CheckRegistry::allWithDirectory(null)
        );
        $this->assertSame(
            CheckRegistry::all(),
            CheckRegistry::allWithDirectory($this->dir . '/does-not-exist')
        );
    }

    /**
     * Two checks with one reference would report the same id twice, and the
     * report is what telemetry counts.
     */
    public function test_a_recipe_check_may_not_shadow_a_shipped_one(): void
    {
        // `_baseline/not-placeholder` is shipped; the same id here is a claim
        // to replace it, which is not what merging means.
        $this->write('checks/_baseline/not-placeholder.yaml', $this->baselineCheck('not-placeholder'));

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('may not shadow');

        CheckRegistry::allWithDirectory($this->dir . '/checks');
    }

    /** A malformed recipe check names its file, not just the group. */
    public function test_a_malformed_recipe_check_says_where_it_is(): void
    {
        $this->write('checks/_baseline/broken.yaml', "id: broken\nmessage: ''\nexpect:\n  status: [200]\n");

        $this->expectException(CheckException::class);
        $this->expectExceptionMessage('recipe _baseline/broken.yaml');

        CheckRegistry::allWithDirectory($this->dir . '/checks');
    }

    public function test_a_reference_is_answered_by_the_recipe_directory(): void
    {
        $this->write('checks/_baseline/api-health.yaml', $this->baselineCheck());

        $this->assertSame(
            ['_baseline/api-health'],
            CheckRegistry::recipeReferences($this->dir . '/checks', ['_baseline/api-health'])
        );
        $this->assertSame([], CheckRegistry::recipeReferences($this->dir . '/checks', ['_baseline/not-here']));
        $this->assertSame([], CheckRegistry::recipeReferences(null, ['_baseline/api-health']));
    }

    /**
     * The end-to-end shape of the feature: a recipe says `check:
     * [custom/api-health]`, its own file is loaded, and the check runs against
     * the path it named.
     */
    public function test_a_recipe_check_runs_against_the_path_it_named(): void
    {
        $this->write('checks/_baseline/api-health.yaml', $this->baselineCheck());

        $report = CheckRunner::forWithDirectory('compose', [], $this->dir . '/checks')
            ->runByPath([
                '/' => new \App\Lib\Deploy\Health\ProbedResponse(200, '<div id="root"></div>', 'http://127.0.0.1:4000/'),
                '/api/health' => new \App\Lib\Deploy\Health\ProbedResponse(200, '{"database":"unreachable"}', 'http://127.0.0.1:4000/api/health'),
            ], null);

        $found = null;
        foreach ($report['checks'] as $check) {
            if ($check['id'] === 'api-health') {
                $found = $check;
            }
        }

        $this->assertNotNull($found, 'the recipe check was not asked');
        $this->assertSame('fail', $found['status']);
        $this->assertSame('database_error', $report['serving']);
    }

    /**
     * `deploy_recipe_dir` is frozen onto the account so a check can find its
     * recipe. Without the resolution the health check would look in the wrong
     * place and report nothing.
     */
    public function test_the_recipe_directory_is_resolved_by_repository_url(): void
    {
        // No shipped recipe for this URL, so nothing is resolved -- the
        // important half being that it answers rather than throwing.
        $this->assertNull(SourceRecipes::directoryFor('https://github.com/nobody/nothing-here.git'));
        $this->assertNull(SourceRecipes::directoryFor(null));
        $this->assertNull(SourceRecipes::directoryFor(''));
    }
}
