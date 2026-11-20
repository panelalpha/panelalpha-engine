<?php

namespace Tests\Integration\Deploy\Checkout;

use App\Lib\Deploy\Checkout\InstallLockfiles;
use PHPUnit\Framework\TestCase;

/**
 * The lockfiles an install writes when the repository ships none, by the manifest it resolves from.
 */
class InstallLockfilesTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = sys_get_temp_dir().'/pa-install-lockfiles-'.bin2hex(random_bytes(4));
        mkdir($this->checkout, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->checkout));
        parent::tearDown();
    }

    public function test_every_manager_a_package_json_can_pick_writes_its_own_lockfile(): void
    {
        $this->put('package.json', "{}\n");

        $this->assertSame([
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',
            'bun.lock',
            'bun.lockb',
        ], InstallLockfiles::candidates($this->checkout));
    }

    /** The top of the checkout always, and a folder only where a manifest sits, never inside a dependency folder. */
    public function test_dependency_folders_are_at_the_top_and_beside_each_manifest_one_level_down(): void
    {
        $this->put('frontend/package.json', "{}\n");
        $this->put('docs/index.md', "# docs\n");
        $this->put('node_modules/pkg/package.json', "{}\n");

        $this->assertSame([
            'node_modules',
            'vendor',
            'bower_components',
            'frontend/node_modules',
            'frontend/vendor',
            'frontend/bower_components',
        ], InstallLockfiles::dependencyFolders($this->checkout));
    }

    public function test_a_checkout_with_no_manifest_writes_no_lockfile(): void
    {
        $this->put('index.html', "hello\n");

        $this->assertSame([], InstallLockfiles::candidates($this->checkout));
    }

    public function test_composer_and_the_other_manifests_name_only_their_own_lockfile(): void
    {
        $this->put('composer.json', "{}\n");
        $this->put('Cargo.toml', "[package]\n");
        $this->put('pyproject.toml', "[tool.poetry]\n");
        $this->put('Pipfile', "[[source]]\n");

        $this->assertSame(
            ['composer.lock', 'Cargo.lock', 'poetry.lock', 'Pipfile.lock'],
            InstallLockfiles::candidates($this->checkout)
        );
    }

    public function test_a_manifest_in_a_folder_the_build_runs_in_counts_too(): void
    {
        $this->put('server/composer.json', "{}\n");
        $this->put('apps/web/package.json', "{}\n");

        $this->assertSame(['server/composer.lock'], InstallLockfiles::candidates($this->checkout));
    }

    public function test_another_packages_folder_is_not_the_projects(): void
    {
        $this->put('node_modules/left-pad/package.json', "{}\n");
        $this->put('vendor/acme/lib/composer.json', "{}\n");
        $this->put('.cache/package.json', "{}\n");

        $this->assertSame([], InstallLockfiles::candidates($this->checkout));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->checkout.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
