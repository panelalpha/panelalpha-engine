<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Platform\Runtime\Php\ComposerManifest;
use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\Lib\Deploy\Platform\Runtime\Php\PhpEnvironment;
use App\System\Project\Dind\Strategy\PhpStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Symfony's production environment is spelled `prod`, and only `prod`.
 *
 * `production` is Laravel's word. Shipping it to a Symfony app is fatal
 * rather than untidy: symfony/runtime reads APP_ENV and then looks for the
 * config named after it, so wallabag died on
 *
 *     The file "/app/app/config/config_production.yml" does not exist.
 *
 * before rendering a byte. The deploy skill already tells an operator to
 * correct this by hand with env_vars -- but the engine detected
 * `symfony/framework-bundle 5.4.45` itself, so it should not need telling.
 */
class SymfonyAppEnvTest extends TestCase
{
    public function test_a_symfony_app_is_given_prod(): void
    {
        $env = PhpEnvironment::for([], null, false, true);

        $this->assertSame('prod', $env['APP_ENV']);
    }

    /** Laravel's word is the other one, and it stays. */
    public function test_a_laravel_app_keeps_production(): void
    {
        $env = PhpEnvironment::for([], null, true, false);

        $this->assertSame('production', $env['APP_ENV']);
    }

    /** So does a plain PHP app that is neither. */
    public function test_a_plain_php_app_keeps_production(): void
    {
        $this->assertSame('production', PhpEnvironment::for([], null, false, false)['APP_ENV']);
    }

    /** Nothing else about the environment moves. */
    public function test_only_app_env_changes(): void
    {
        $symfony = PhpEnvironment::for([], 'https://example.test', false, true);
        $plain = PhpEnvironment::for([], 'https://example.test', false, false);

        unset($symfony['APP_ENV'], $plain['APP_ENV']);
        $this->assertSame($plain, $symfony);
        $this->assertSame('stderr', $plain['LOG_CHANNEL']);
    }

    /** Wallabag's own root require, and Symfony's older monolithic package. */
    public function test_both_spellings_are_recognised(): void
    {
        $bundle = new ComposerManifest('{"require":{"symfony/framework-bundle":"^5.4"}}', null);
        $monolith = new ComposerManifest('{"require":{"symfony/symfony":"^4.4"}}', null);

        $this->assertTrue($bundle->rootRequires('symfony/framework-bundle'));
        $this->assertTrue($monolith->rootRequires('symfony/symfony'));
    }

    /**
     * The reason this reads the root require and not the flattened graph:
     * half of Packagist depends on a Symfony component, and "depends on
     * symfony/console" is not "is a Symfony application". Laravel is the
     * example that would have been misdetected.
     */
    public function test_a_dependency_on_a_symfony_component_is_not_a_symfony_app(): void
    {
        $laravel = new ComposerManifest(
            '{"require":{"laravel/framework":"^11.0","symfony/console":"^7.0"}}',
            '{"packages":[{"name":"laravel/framework","require":{"symfony/framework-bundle":"^7.0"}}]}'
        );

        $this->assertFalse(
            $laravel->rootRequires('symfony/framework-bundle'),
            'a locked package requiring it must not make the project Symfony'
        );
        $this->assertFalse($laravel->rootRequires('symfony/symfony'));
    }

    /** Thelia: the root requires thelia/core, which brings the framework in. */
    public function test_a_skeleton_whose_core_package_installs_the_framework_is_symfony(): void
    {
        $build = new PhpBuild(composerJson: '{"require":{"thelia/core":"^3.1","symfony/flex":"^2.4"}}', artisan: false);
        $installed = '{"packages":[{"name":"thelia/core"},{"name":"symfony/framework-bundle"}],"dev":false}';

        $this->assertFalse(PhpStrategy::isSymfony($build), 'the root manifest alone does not say so');
        $this->assertTrue(PhpStrategy::isSymfony($build, $installed));
    }

    public function test_a_lock_pinning_the_framework_is_symfony(): void
    {
        $build = new PhpBuild(
            composerJson: '{"require":{"shopware/core":"^6.6"}}',
            composerLock: '{"packages":[{"name":"shopware/core"},{"name":"symfony/framework-bundle"}]}',
            artisan: false
        );

        $this->assertTrue(PhpStrategy::isSymfony($build));
    }

    /** Composer 1 wrote installed.json as a bare list. */
    public function test_a_composer_1_installed_list_is_read(): void
    {
        $build = new PhpBuild(composerJson: '{"require":{"acme/core":"^1"}}', artisan: false);

        $this->assertTrue(PhpStrategy::isSymfony($build, '[{"name":"symfony/symfony"}]'));
    }

    /** Laravel stays Laravel, whatever a package pulls in. */
    public function test_laravel_with_the_framework_installed_is_not_symfony(): void
    {
        $build = new PhpBuild(composerJson: '{"require":{"laravel/framework":"^11.0"}}', artisan: true);

        $this->assertFalse(PhpStrategy::isSymfony($build, '{"packages":[{"name":"symfony/framework-bundle"}]}'));
    }

    public function test_the_vendor_dir_follows_composer_config(): void
    {
        $this->assertSame('vendor', (new ComposerManifest('{}', null))->vendorDir());
        $this->assertSame('packages', (new ComposerManifest('{"config":{"vendor-dir":"packages"}}', null))->vendorDir());
        $this->assertSame('vendor', (new ComposerManifest('{"config":{"vendor-dir":"../up"}}', null))->vendorDir());
        $this->assertSame('vendor', (new ComposerManifest('{"config":{"vendor-dir":"/etc"}}', null))->vendorDir());
    }

    /** No composer.json at all is not Symfony. */
    public function test_a_project_without_composer_json_is_not_symfony(): void
    {
        $this->assertFalse((new ComposerManifest(null, null))->rootRequires('symfony/framework-bundle'));
    }

    /**
     * One authority, so the preview cannot contradict the deploy.
     *
     * php.yaml used to carry `APP_ENV: production` as well, and a static
     * manifest cannot ask which framework this is. The container got `prod`
     * from PhpEnvironment while `source_inspect` and `project_inspect` both
     * went on reporting `environment.defaults.APP_ENV: production` -- the two
     * halves of the engine telling an operator different things about the
     * same deploy.
     */
    public function test_no_platform_manifest_declares_app_env(): void
    {
        $manifests = glob(__DIR__ . '/../../../resources/platforms/*.yaml') ?: [];
        $this->assertNotEmpty($manifests, 'the platform manifests must be findable');

        foreach ($manifests as $file) {
            $declared = Yaml::parseFile($file)['env'] ?? null;
            $this->assertArrayNotHasKey(
                'APP_ENV',
                is_array($declared) ? $declared : [],
                basename($file) . ' declares APP_ENV; PhpEnvironment is the only place that decides it'
            );
        }
    }
}
