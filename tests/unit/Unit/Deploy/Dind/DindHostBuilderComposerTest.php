<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * The host-side `composer install`, and which PHP it resolves against.
 *
 * Composer resolves for whatever PHP it can see, which here is the Composer
 * image's own — newer than the app's. Left unpinned it locked
 * symfony/http-foundation 8.1 (php >=8.4.1) for a Laravel that runs on 8.3; the
 * container then died on a ParseError inside vendor/ before serving a request,
 * and the deploy still reported success. Pinned, the same project resolves
 * symfony 7.4 and boots.
 */
class DindHostBuilderComposerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function build(?string $php, ?string $manifest = null): array
    {
        $account = new EngineAccount('acme', '/home/acme');

        return LocalRoot::build((new DindHostBuilder)->composerInstallArgv($account, $php, $manifest));
    }

    private function script(?string $php, ?string $manifest = null): string
    {
        return $this->build($php, $manifest)['script'];
    }

    public function test_the_install_resolves_for_the_apps_php_not_the_images(): void
    {
        $this->assertStringContainsString('composer config --no-plugins platform.php 8.3', $this->script('8.3'));
    }

    public function test_extensions_stay_ignored(): void
    {
        // The Composer image does not carry the app's extension set; those
        // are satisfied by the runtime image instead. Only the PHP *version*
        // has to be honoured here.
        $script = $this->script('8.3');

        $this->assertStringContainsString("--ignore-platform-req='ext-*'", $script);
        $this->assertStringNotContainsString('--ignore-platform-reqs', $script);
    }

    public function test_a_project_with_no_composer_json_keeps_the_old_behaviour(): void
    {
        // Nothing to resolve for, so nothing to pin.
        $this->assertStringContainsString('--ignore-platform-reqs', $this->script(null));
    }

    public function test_a_version_that_is_not_a_minor_is_not_interpolated(): void
    {
        // The value reaches here from a project's own composer.json. It ends
        // up inside a shell command, so anything unexpected falls back rather
        // than being pasted in.
        foreach (['8', '8.3.1', '$(id)', '8.3; rm -rf /', ''] as $bogus) {
            $script = $this->script($bogus);

            $this->assertStringContainsString('--ignore-platform-reqs', $script, $bogus);
            $this->assertStringNotContainsString('platform.php', $script, $bogus);
        }
    }

    public function test_scripts_and_plugins_stay_disabled(): void
    {
        // Both execute arbitrary PHP from the customer's repository, and this
        // install runs on the host daemon rather than in the account sandbox.
        foreach ([null, '8.3'] as $php) {
            $script = $this->script($php);

            $this->assertStringContainsString('--no-scripts', $script);
            $this->assertStringContainsString('--no-plugins', $script);
        }
    }

    public function test_the_install_is_confined_to_the_accounts_project(): void
    {
        $this->assertContains('/home/acme/project:/app', $this->build('8.3')['mounts']);
    }

    public function test_a_project_directory_outside_the_account_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DindHostBuilder)->composerInstallArgv(new EngineAccount('acme', '/etc'), '8.3');
    }

    /**
     * The dev-only advisory fix: when the caller has written a
     * require-dev-less manifest, Composer is pointed at it for the resolve.
     * See {@see PhpHostBuild::runtimeManifest()}.
     */
    public function test_a_runtime_manifest_is_handed_to_composer_as_its_manifest(): void
    {
        $environment = $this->build('8.3', PhpHostBuild::RUNTIME_MANIFEST_FILE)['environment'];

        $this->assertSame(PhpHostBuild::RUNTIME_MANIFEST_FILE, $environment[PhpHostBuild::MANIFEST_ENV] ?? null);
    }

    public function test_without_a_runtime_manifest_composer_reads_the_project_itself(): void
    {
        foreach ([null, '8.3'] as $php) {
            $this->assertArrayNotHasKey(PhpHostBuild::MANIFEST_ENV, $this->build($php)['environment']);
        }
    }

    /**
     * A lockless project whose runtime `require` has an advisory on every
     * allowed version (providence's league/flysystem 1.x) could not resolve at
     * all. Advisories go to the audit in the log, not into the resolver.
     */
    public function test_advisories_never_block_the_resolve(): void
    {
        foreach ([null, PhpHostBuild::RUNTIME_MANIFEST_FILE] as $manifest) {
            $this->assertSame('1', $this->build('8.3', $manifest)['environment']['COMPOSER_NO_BLOCKING'] ?? null);
        }
    }

    public function test_the_install_still_excludes_dev_packages(): void
    {
        $this->assertStringContainsString('--no-dev', $this->script('8.3', PhpHostBuild::RUNTIME_MANIFEST_FILE));
    }
}
