<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\Php\ComposerManifest;
use App\Lib\Deploy\Platform\Runtime\Php\PhpExtensions;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Template\ResourceDirectory;
use App\Lib\Deploy\Template\TemplateLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class PhpBaseImageTest extends TestCase
{
    public function test_tag_is_derived_from_the_official_php_tag(): void
    {
        $tag = PhpBaseImage::tag('php:8.5-apache-bookworm');

        $this->assertSame(
            PhpBaseImage::repository() . ':8.5-apache-bookworm-pa' . PhpBaseImage::fingerprint(),
            $tag
        );
    }

    public function test_tag_changes_with_the_baked_extension_set(): void
    {
        $this->assertMatchesRegularExpression('/-pa\d{8}$/', (string) PhpBaseImage::tag('php:8.3-apache-bookworm'));
    }

    public function test_no_tag_for_images_we_do_not_build(): void
    {
        $this->assertNull(PhpBaseImage::tag('ghcr.io/acme/php:8.3'));
        $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm AS app'));
        $this->assertNull(PhpBaseImage::tag(''));
    }

    public function test_source_image_round_trips_the_tag(): void
    {
        $tag = (string) PhpBaseImage::tag('php:8.5-apache-bookworm');

        $this->assertSame('php:8.5-apache-bookworm', PhpBaseImage::sourceImage($tag));
        $this->assertNull(PhpBaseImage::sourceImage('php:8.5-apache-bookworm'));
        $this->assertNull(PhpBaseImage::sourceImage('mysql:8.4'));
        $this->assertNull(PhpBaseImage::sourceImage(PhpBaseImage::repository() . ':-pa1234abcd'));
    }

    public function test_only_extensions_outside_the_baked_set_are_left_for_the_app(): void
    {
        $missing = PhpBaseImage::missingExtensions(['intl', 'imagick', 'pdo_mysql', 'grpc']);

        $this->assertSame(['grpc'], $missing);
    }

    /**
     * The base image is the runtime now: nothing downstream installs what it
     * does not carry, so an extension missing here is missing from the served
     * site. Every one of these was measured as required by an application the
     * engine ships a manifest for -- mysqli by all of them, because an app
     * handed a database picks its own driver and WordPress, Matomo,
     * phpMyAdmin and Adminer all pick mysqli.
     */
    public function test_the_drivers_every_shipped_application_needs_are_baked(): void
    {
        foreach (['mysqli', 'pdo_mysql', 'ftp', 'soap', 'xsl', 'imagick', 'gd', 'intl', 'zip'] as $extension) {
            $this->assertContains(
                $extension,
                PhpBaseImage::EXTENSIONS,
                "{$extension} is not baked, and there is no per-project build left to add it"
            );
        }
    }

    /**
     * The tag says when the image was defined, so an operator reading
     * `docker images` can tell a current base from one built months ago.
     */
    public function test_the_fingerprint_is_the_declared_recipe_date(): void
    {
        $this->assertMatchesRegularExpression('/^\d{8}$/', PhpBaseImage::fingerprint());
        $this->assertNotFalse(
            \DateTimeImmutable::createFromFormat('Ymd', PhpBaseImage::fingerprint()),
            'the recipe date has to be a real date'
        );
    }

    /**
     * The date is what the config declares, and bumping it is what an operator
     * does after changing the recipe. Nothing detects a recipe change for
     * them, so the least this can do is guarantee the bump lands in the tag.
     */
    public function test_bumping_the_configured_date_changes_the_tag(): void
    {
        $before = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');
        $config = tempnam(sys_get_temp_dir(), 'runtime-images') . '.yaml';

        try {
            file_put_contents($config, <<<'YAML'
            runtimes:
              php:
                default: "8.3"
                versions: ["8.3"]
                image:
                  from: "php:{version}-apache-bookworm"
                  build:
                    repository: panelalpha/php
                    stub: dockerfile/php-base
                    recipe: "2031-01-02"
            YAML);
            RuntimeImageCatalog::useConfig($config);

            $this->assertSame('20310102', PhpBaseImage::fingerprint());
            $this->assertSame(
                'panelalpha/php:8.3-apache-bookworm-pa20310102',
                PhpBaseImage::tag('php:8.3-apache-bookworm')
            );
        } finally {
            RuntimeImageCatalog::useConfig(null);
            @unlink($config);
        }

        $this->assertSame($before, PhpBaseImage::tag('php:8.3-apache-bookworm'));
    }

    /**
     * A date that is not one leaves the runtime with no tag at all, rather
     * than a tag stamped with something that is not a date.
     *
     * There is nothing compiled in to land on any more: a fallback would name
     * an image the catalogue does not describe, and the deploy would build it,
     * fail to recognise it, and fetch it from a registry that never had it.
     * No shared base is slower; a base nobody can identify is a dead deploy.
     */
    public function test_an_unparseable_date_leaves_no_tag_at_all(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'runtime-images') . '.yaml';

        try {
            file_put_contents($config, <<<'YAML'
            runtimes:
              php:
                default: "8.3"
                versions: ["8.3"]
                image:
                  from: "php:{version}-apache-bookworm"
                  build:
                    repository: panelalpha/php
                    stub: dockerfile/php-base
                    recipe: "yesterday"
            YAML);
            RuntimeImageCatalog::useConfig($config);

            $this->assertNull(PhpBaseImage::fingerprint());
            $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm'));
        } finally {
            RuntimeImageCatalog::useConfig(null);
            @unlink($config);
        }
    }

    public function test_dockerfile_bakes_the_toolchain_the_app_stage_would_otherwise_install(): void
    {
        $dockerfile = PhpBaseImage::dockerfile('php:8.5-apache-bookworm');

        $this->assertStringContainsString('FROM php:8.5-apache-bookworm', $dockerfile);
        $this->assertStringContainsString('install-php-extensions ' . implode(' ', PhpBaseImage::EXTENSIONS), $dockerfile);
        $this->assertStringContainsString('git unzip', $dockerfile);
        // The DB clients Laravel's schema:dump replay shells out to.
        $this->assertStringContainsString('default-mysql-client', $dockerfile);
        $this->assertStringContainsString('postgresql-client', $dockerfile);
    }

    public function test_extras_get_their_own_variant_tag(): void
    {
        $plain = (string) PhpBaseImage::tag('php:8.1-apache-bookworm');
        $variant = (string) PhpBaseImage::tag('php:8.1-apache-bookworm', ['imagick']);

        $this->assertNotSame($plain, $variant);
        $this->assertStringStartsWith($plain . '-x', $variant);
        $this->assertMatchesRegularExpression('/-x[0-9a-f]{8}$/', $variant);
    }

    public function test_variant_tag_ignores_order_and_case_and_duplicates(): void
    {
        $this->assertSame(
            PhpBaseImage::tag('php:8.1-apache-bookworm', ['imagick', 'grpc']),
            PhpBaseImage::tag('php:8.1-apache-bookworm', ['GRPC', 'imagick', 'imagick'])
        );
    }

    public function test_variant_tag_still_names_the_php_image_it_came_from(): void
    {
        $variant = (string) PhpBaseImage::tag('php:8.1-apache-bookworm', ['imagick']);

        $this->assertSame('php:8.1-apache-bookworm', PhpBaseImage::sourceImage($variant));
    }

    public function test_baked_extras_are_not_installed_again_in_the_app_stage(): void
    {
        $this->assertSame(
            ['grpc'],
            PhpBaseImage::missingExtensions(['intl', 'imagick', 'grpc'], ['imagick'])
        );
    }

    public function test_dockerfile_compiles_extras_in_a_layer_after_the_shared_set(): void
    {
        $dockerfile = PhpBaseImage::dockerfile('php:8.1-apache-bookworm', ['imagick']);

        $shared = strpos($dockerfile, 'install-php-extensions ' . implode(' ', PhpBaseImage::EXTENSIONS));
        $extra = strpos($dockerfile, 'install-php-extensions imagick');
        $this->assertNotFalse($shared);
        $this->assertNotFalse($extra);
        $this->assertGreaterThan($shared, $extra, 'extras must not invalidate the layer every app shares');
    }

    public function test_dockerfile_without_extras_is_unchanged(): void
    {
        $this->assertSame(
            PhpBaseImage::dockerfile('php:8.1-apache-bookworm'),
            PhpBaseImage::dockerfile('php:8.1-apache-bookworm', [])
        );
    }

    public function test_a_long_tail_of_extensions_is_not_worth_a_host_image(): void
    {
        $many = ['imagick', 'grpc', 'ffi', 'yaml', 'mongodb', 'xdebug'];

        $this->assertSame([], PhpBaseImage::bakeableExtras($many));
        $this->assertSame(['grpc', 'imagick'], PhpBaseImage::bakeableExtras(['imagick', 'grpc']));
    }

    public function test_bakeable_extras_reject_names_that_are_not_extension_names(): void
    {
        $this->assertSame([], PhpBaseImage::bakeableExtras(['; rm -rf /', '--flag']));
    }

    /**
     * The extensions a project *requires* are a different question from the
     * extensions it will get, and only the first one decides whether a variant
     * may be deferred.
     *
     * `for()` merges in the suggestions and the engine's own database driver,
     * so a project can end up on a variant for an extension it only suggests.
     * That variant may be built behind the deploy. One it *requires* may not:
     * the plain base cannot satisfy it and there is no per-project Dockerfile
     * to compile it, so the deploy fails.
     */
    public function test_required_for_reports_only_what_the_project_requires(): void
    {
        $composer = (string) json_encode([
            'require' => ['php' => '^8.3', 'ext-mailparse' => '*'],
            'suggest' => ['ext-redis' => 'for caching', 'ext-gd' => 'for images'],
        ]);

        $manifest = new ComposerManifest($composer, null);

        $this->assertContains('mailparse', PhpExtensions::requiredFor($manifest));
        $this->assertNotContains('redis', PhpExtensions::requiredFor($manifest));
        $this->assertNotContains('gd', PhpExtensions::requiredFor($manifest));
    }

    /** A locked package's own `require` is a requirement of the deploy too. */
    public function test_required_for_includes_a_locked_packages_requirement(): void
    {
        $lock = (string) json_encode([
            'packages' => [
                ['name' => 'react/zmq', 'require' => ['ext-zmq' => '*']],
            ],
        ]);

        $manifest = new ComposerManifest((string) json_encode(['require' => ['php' => '^8.3']]), $lock);

        $this->assertContains('zmq', PhpExtensions::requiredFor($manifest));
    }

    /** Built into the php image, so requiring it is not a variant. */
    public function test_required_for_ignores_bundled_extensions(): void
    {
        $manifest = new ComposerManifest(
            (string) json_encode(['require' => ['ext-json' => '*', 'ext-mbstring' => '*']]),
            null
        );

        $this->assertSame([], PhpExtensions::requiredFor($manifest));
    }

    /**
     * A variant the catalogue names has to be one a deploy can also arrive at.
     *
     * The two sides compute the same tag from opposite directions: the deploy
     * from a composer.json, through
     * `bakeableExtras(missingExtensions(PhpExtensions::for(...)))`; the
     * catalogue from an `extensions:` list an operator typed. If the catalogue
     * skips that reduction, a set holding an extension the base already bakes
     * -- `["mongodb", "gd"]` -- hashes one way there and another way here. The
     * host then builds and keeps an image nothing asks for, while the app that
     * needed mongodb still compiles it on its first deploy. Nothing errors, so
     * only this test would notice.
     */
    public function test_a_declared_extension_set_reduces_the_way_a_deploy_reduces_it(): void
    {
        $cases = [
            // declared                 what a deploy requiring the same arrives at
            [['mongodb'], ['mongodb']],
            // gd is in the baked set, so it is not part of any variant
            [['mongodb', 'gd'], ['mongodb']],
            [['gd'], []],
            // order and case cannot change the set
            [['tidy', 'mongodb'], ['mongodb', 'tidy']],
            [['MongoDB'], ['mongodb']],
            // past MAX_BAKED_EXTRAS there is no variant, only a plain base
            [['mailparse', 'mongodb', 'tidy', 'zmq', 'swoole'], []],
            // PHP bundles these, so a composer.json requiring them never
            // reaches the image machinery with them and neither may a
            // catalogue entry
            [['json'], []],
            [['mbstring', 'mongodb'], ['mongodb']],
        ];

        foreach ($cases as [$declared, $expected]) {
            $this->assertSame(
                $expected,
                PhpBaseImage::bakeableExtras(
                    PhpBaseImage::missingExtensions(PhpExtensions::installable($declared))
                ),
                json_encode($declared)
            );
        }
    }

    /**
     * The same invariant against the shipped catalogue: every `extensions:`
     * entry in config/core/images.yaml must already be in reduced form, or the
     * tag it resolves to is one no deploy will ask for.
     */
    public function test_every_shipped_variant_entry_is_already_reduced(): void
    {
        $config = Yaml::parseFile(__DIR__ . '/../../../../../config/core/images.yaml');
        $declared = [];
        foreach (($config['runtimes']['php']['versions'] ?? []) as $entry) {
            if (is_array($entry) && is_array($entry['extensions'] ?? null)) {
                $declared[] = $entry['extensions'];
            }
        }

        $this->assertNotSame([], $declared, 'the catalogue should still ship variant entries');
        foreach ($declared as $extensions) {
            $this->assertSame(
                PhpBaseImage::normalizeExtras($extensions),
                PhpBaseImage::bakeableExtras(
                    PhpBaseImage::missingExtensions(PhpExtensions::installable($extensions))
                ),
                json_encode($extensions) . ' names a variant no deploy can compute'
            );
        }
    }
}
