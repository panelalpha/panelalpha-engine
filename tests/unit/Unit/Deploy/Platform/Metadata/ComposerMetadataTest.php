<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\ComposerMetadata;

class ComposerMetadataTest extends MetadataTestCase
{
    public function test_it_reads_identity_from_composer_json(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/shop',
            'description' => 'Storefront',
            'type' => 'project',
            'license' => 'MIT',
            'homepage' => 'https://acme.test',
            'keywords' => ['shop', 'commerce'],
            'authors' => [['name' => 'Jane Doe', 'email' => 'jane@acme.test']],
            'require' => ['php' => '^8.3', 'ext-gd' => '*', 'laravel/framework' => '^11.0'],
            'require-dev' => ['phpunit/phpunit' => '^11.0'],
            'scripts' => ['post-autoload-dump' => 'x', 'test' => 'y'],
        ]);

        $package = (new ComposerMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('php', $package->ecosystem);
        $this->assertSame('acme/shop', $package->name);
        $this->assertSame('composer.json name', $package->nameSource);
        $this->assertSame('Storefront', $package->description);
        $this->assertSame('MIT', $package->license);
        $this->assertSame(['Jane Doe'], $package->authors);
        $this->assertTrue($package->private);
        // Names, never bodies: a script line can carry a token.
        $this->assertSame(['post-autoload-dump', 'test'], $package->scripts);
        // php and ext-* are not packages anyone installed.
        $this->assertSame(['require' => 1, 'require-dev' => 1], $package->dependencyCounts);
        // They are the platform instead, kept as declared.
        $this->assertSame(['ext-gd' => '*', 'php' => '^8.3'], $package->platform);
    }

    public function test_platform_names_are_composers_list_and_nothing_that_merely_looks_like_it(): void
    {
        // php-di/php-di is matomo/matomo's, and it is a container library.
        // php-legacy is nobody's platform. composer-runtime-api is a platform
        // requirement that nothing installs, so it must not be counted as a
        // dependency either.
        $this->writeJson('composer.json', [
            'name' => 'matomo/matomo',
            'require' => [
                'php' => '>=8.1.0',
                'php-64bit' => '*',
                'composer-runtime-api' => '^2.0',
                'ext-json' => '*',
                'lib-libxml' => '*',
                'hhvm' => '*',
                'php-di/php-di' => '^7.0',
                'php-legacy' => '^1.0',
                'monolog/monolog' => '^3.0',
            ],
        ]);

        $package = (new ComposerMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame(
            [
                'composer-runtime-api' => '^2.0',
                'ext-json' => '*',
                'hhvm' => '*',
                'lib-libxml' => '*',
                'php' => '>=8.1.0',
                'php-64bit' => '*',
            ],
            $package->platform
        );
        // The three that are packages are counted as packages.
        $this->assertSame(['require' => 3, 'require-dev' => 0], $package->dependencyCounts);
    }

    public function test_the_platform_is_the_runtime_its_extensions_and_its_libraries(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/shop',
            'require' => [
                'PHP' => '^8.2',
                'ext-intl' => '*',
                'lib-openssl' => '>=1.1',
                'laravel/framework' => '^11.0',
            ],
            // A dev-only extension is not something the built image must have.
            'require-dev' => ['ext-xdebug' => '*'],
        ]);

        $package = (new ComposerMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame(
            ['ext-intl' => '*', 'lib-openssl' => '>=1.1', 'php' => '^8.2'],
            $package->platform
        );
    }

    public function test_the_framework_version_comes_from_the_lockfile(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/shop',
            'require' => ['laravel/framework' => '^11.0'],
        ]);
        $this->writeJson('composer.lock', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v11.31.0']],
        ]);

        $framework = (new ComposerMetadata())->read($this->context())?->framework();

        $this->assertNotNull($framework);
        $this->assertSame('Laravel', $framework->name);
        $this->assertSame('^11.0', $framework->constraint);
        // Composer writes the tag as it found it; v11.31.0 and 11.31.0 are one release.
        $this->assertSame('11.31.0', $framework->version);
        $this->assertSame('composer.lock', $framework->source);
        $this->assertSame(11, $framework->major());
    }

    public function test_without_a_lockfile_the_range_is_reported_as_a_range(): void
    {
        $this->writeJson('composer.json', ['require' => ['laravel/framework' => '^10.0']]);

        $framework = (new ComposerMetadata())->read($this->context())?->framework();

        $this->assertNotNull($framework);
        $this->assertNull($framework->version);
        $this->assertSame('composer.json', $framework->source);
    }

    public function test_an_unnamed_project_falls_back_to_the_directory_and_says_so(): void
    {
        $this->writeJson('composer.json', ['require' => ['php' => '^8.3']]);

        $package = (new ComposerMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame(basename($this->tmpDir), $package->name);
        $this->assertSame(AppPackage::NAME_FROM_DIRECTORY, $package->nameSource);
        $this->assertFalse($package->isNamed());
    }

    public function test_a_malformed_composer_json_is_still_a_php_project(): void
    {
        $this->write('composer.json', '{ not json');

        $package = (new ComposerMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('php', $package->ecosystem);
        $this->assertSame(AppPackage::NAME_FROM_DIRECTORY, $package->nameSource);
    }

    public function test_no_composer_json_is_no_php_package(): void
    {
        $this->assertNull((new ComposerMetadata())->read($this->context()));
    }
}
