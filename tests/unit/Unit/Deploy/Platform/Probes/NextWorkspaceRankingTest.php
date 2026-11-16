<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\NextWorkspaceProbe;

/**
 * The whole record {@see NextWorkspaceProbe::findWorkspaceApp()} returns across groups, ties and a `web` package.
 */
class NextWorkspaceRankingTest extends ProbeTestCase
{
    /** @param array<string, mixed> $package */
    private function package(string $dir, array $package): void
    {
        $this->write($dir . '/package.json', (string) json_encode($package));
    }

    public function test_a_web_directory_that_is_the_package_outranks_every_child(): void
    {
        $this->write('apps/web/next.config.js', 'module.exports = {}');
        $this->package('apps/web', ['name' => 'config-only']);
        $this->package('apps/docs', ['dependencies' => ['next' => '15']]);
        $this->write('packages/storefront/next.config.mjs', 'export default {}');
        $this->package('packages/storefront', ['name' => 'storefront', 'dependencies' => ['next' => '15']]);
        $this->package('web', ['name' => 'web-root', 'dependencies' => ['next' => '15']]);

        $found = NextWorkspaceProbe::findWorkspaceApp($this->dir . '/');

        $this->assertSame([
            'dir' => $this->dir . '/web',
            'relative' => 'web',
            'package' => ['name' => 'web-root', 'dependencies' => ['next' => '15']],
        ], $found);
    }

    public function test_a_child_that_scores_higher_keeps_its_place_over_the_web_package(): void
    {
        $this->write('apps/storefront/next.config.js', 'module.exports = {}');
        $this->package('apps/storefront', ['name' => 'store', 'dependencies' => ['next' => '15']]);
        $this->write('web/next.config.js', 'module.exports = {}');
        $this->package('web', ['name' => 'web-config-only']);

        $found = NextWorkspaceProbe::findWorkspaceApp($this->dir);

        $this->assertSame('apps/storefront', $found['relative'] ?? null);
        $this->assertSame($this->dir . '/apps/storefront', $found['dir'] ?? null);
    }

    public function test_equal_scores_keep_the_first_found(): void
    {
        $this->package('apps/beta', ['name' => 'beta', 'dependencies' => ['next' => '15']]);
        $this->package('apps/alpha', ['name' => 'alpha', 'dependencies' => ['next' => '15']]);
        $this->package('packages/gamma', ['name' => 'gamma', 'dependencies' => ['next' => '15']]);
        $this->package('clients/Docs', ['name' => 'docs', 'dependencies' => ['next' => '15']]);

        $this->assertSame('apps/alpha', NextWorkspaceProbe::findWorkspaceApp($this->dir)['relative'] ?? null);
    }

    public function test_a_web_package_with_a_web_child_is_judged_by_its_children(): void
    {
        $this->package('web', ['name' => 'outer', 'dependencies' => ['next' => '15']]);
        $this->package('web/web', ['name' => 'inner', 'devDependencies' => ['next' => '15']]);

        $this->assertSame([
            'dir' => $this->dir . '/web/web',
            'relative' => 'web/web',
            'package' => ['name' => 'inner', 'devDependencies' => ['next' => '15']],
        ], NextWorkspaceProbe::findWorkspaceApp($this->dir));
    }

    public function test_nothing_qualifies(): void
    {
        $this->package('apps/api', ['dependencies' => ['express' => '4']]);
        $this->write('apps/notes.txt', 'x');
        $this->package('web', ['dependencies' => ['vite' => '5']]);

        $this->assertNull(NextWorkspaceProbe::findWorkspaceApp($this->dir));
    }

    public function test_the_probe_reports_package_and_slug_for_a_workspace_hit(): void
    {
        $this->package('apps/site', ['name' => ' @acme/site ', 'dependencies' => ['next' => '15']]);

        $this->assertSame(
            ['workspace_relative' => 'apps/site', 'workspace_package' => '@acme/site', 'workspace_slug' => 'site'],
            (new NextWorkspaceProbe())->evaluate($this->context())
        );
    }
}
