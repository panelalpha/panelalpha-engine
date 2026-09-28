<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\NextWorkspaceProbe;

/**
 * Which package in a monorepo is the Next.js app being deployed.
 *
 * The root package.json of a Turborepo says nothing useful, and several
 * workspaces may declare `next` at once. Building `apps/docs` instead of
 * `apps/web` deploys successfully and serves the wrong site.
 */
class NextWorkspaceProbeTest extends ProbeTestCase
{
    private function probe(): NextWorkspaceProbe
    {
        return new NextWorkspaceProbe();
    }

    public function test_a_root_next_dependency_means_a_single_package_app(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['next' => '^14.0.0']]);

        $this->assertSame(['workspace_relative' => ''], $this->probe()->evaluate($this->context()));
    }

    public function test_a_root_next_config_means_a_single_package_app(): void
    {
        $this->writeJson('package.json', ['dependencies' => []]);
        $this->write('next.config.js', 'module.exports = {};');

        $this->assertSame(['workspace_relative' => ''], $this->probe()->evaluate($this->context()));
    }

    public function test_a_root_that_declares_next_ignores_a_workspace_hit(): void
    {
        // A root declaring Next itself is the app. A workspace that also
        // declares it is a second app, not the one being deployed.
        $this->writeJson('package.json', ['dependencies' => ['next' => '^14.0.0']]);
        $this->writeJson('apps/web/package.json', ['dependencies' => ['next' => '^14.0.0']]);

        $this->assertSame(['workspace_relative' => ''], $this->probe()->evaluate($this->context()));
    }

    public function test_a_single_workspace_app_is_found(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*']]);
        $this->writeJson('apps/web/package.json', [
            'name' => '@acme/web',
            'dependencies' => ['next' => '^14.0.0'],
        ]);

        $this->assertSame([
            'workspace_relative' => 'apps/web',
            'workspace_package' => '@acme/web',
            'workspace_slug' => 'web',
        ], $this->probe()->evaluate($this->context()));
    }

    public function test_the_site_beats_the_documentation(): void
    {
        // Both are real Next apps. `docs` is scored down because deploying a
        // storefront's documentation in place of the storefront is the
        // failure nobody notices.
        $this->writeJson('package.json', ['workspaces' => ['apps/*']]);
        $this->writeJson('apps/docs/package.json', ['dependencies' => ['next' => '^14.0.0']]);
        $this->writeJson('apps/web/package.json', ['dependencies' => ['next' => '^14.0.0']]);

        $this->assertSame('apps/web', $this->probe()->evaluate($this->context())['workspace_relative']);
    }

    public function test_an_unrecognised_workspace_name_still_beats_a_deprioritised_one(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*']]);
        $this->writeJson('apps/admin/package.json', ['dependencies' => ['next' => '^14.0.0']]);
        $this->writeJson('apps/shop/package.json', ['dependencies' => ['next' => '^14.0.0']]);

        $this->assertSame('apps/shop', $this->probe()->evaluate($this->context())['workspace_relative']);
    }

    public function test_a_package_declaring_next_beats_a_config_only_stub(): void
    {
        // Equal names, so the tiebreak is which one actually installs Next.
        $this->writeJson('package.json', ['workspaces' => ['apps/*', 'packages/*']]);
        $this->writeJson('apps/site/package.json', ['dependencies' => []]);
        $this->write('apps/site/next.config.js', 'module.exports = {};');
        $this->writeJson('packages/site/package.json', ['dependencies' => ['next' => '^14.0.0']]);

        $this->assertSame('packages/site', $this->probe()->evaluate($this->context())['workspace_relative']);
    }

    public function test_the_app_with_its_own_next_config_beats_a_server_that_imports_next(): void
    {
        // engine#160, Teable: nestjs-backend depends on next to serve the app
        // and sorts first; nextjs-app is the app.
        $this->writeJson('package.json', ['name' => '@teable/teable']);
        $this->write('pnpm-workspace.yaml', "packages:\n  - apps/*\n");
        $this->writeJson('apps/nestjs-backend/package.json', ['dependencies' => ['next' => '14.2.0']]);
        $this->writeJson('apps/nextjs-app/package.json', ['name' => '@teable/app', 'dependencies' => ['next' => '14.2.0']]);
        $this->write('apps/nextjs-app/next.config.js', 'module.exports = {};');

        $this->assertSame('apps/nextjs-app', $this->probe()->evaluate($this->context())['workspace_relative']);
    }

    public function test_a_web_directory_that_is_itself_the_package_is_found(): void
    {
        // `web/` holding the app directly, rather than `web/<name>/`.
        $this->writeJson('package.json', ['private' => true]);
        $this->writeJson('web/package.json', [
            'name' => 'frontend',
            'dependencies' => ['next' => '^14.0.0'],
        ]);

        $this->assertSame([
            'workspace_relative' => 'web',
            'workspace_package' => 'frontend',
            'workspace_slug' => 'web',
        ], $this->probe()->evaluate($this->context()));
    }

    public function test_a_workspace_without_next_is_no_match(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*']]);
        $this->writeJson('apps/api/package.json', ['dependencies' => ['express' => '^4.18.0']]);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_directory_without_a_package_json_is_skipped(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*']]);
        $this->write('apps/web/next.config.js', 'module.exports = {};');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
