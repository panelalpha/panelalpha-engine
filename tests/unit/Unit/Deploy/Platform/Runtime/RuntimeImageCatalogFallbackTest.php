<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * What a bad config does.
 *
 * The rule the whole design rests on: a host edits this file, and nothing it
 * can write may stop a deploy. Every shape below is one an operator plausibly
 * produces — a half-finished edit, a stray tab, a default they forgot to add
 * to the version list — and every one has to end with a project still
 * resolving to a version and still running on the official image for it.
 *
 * The images the engine *builds* are a different matter: they are named from
 * this file and nowhere else, so a bad edit leaves a runtime with no shared
 * base and every account on it installing its own extensions. Slower, and
 * still a deploy.
 *
 * These are the branches the shipped config cannot reach, because the shipped
 * config is the good case. Without them the fallback path is code nothing ever
 * runs until a customer runs it.
 *
 * @see \Tests\Unit\Deploy\Platform\Runtime\RuntimeImageCatalogTest for the
 *      rules the real shipped config has to satisfy.
 */
class RuntimeImageCatalogFallbackTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        RuntimeImageCatalog::useConfig(null);
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];

        parent::tearDown();
    }

    private function config(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'runtime-images-') . '.yaml';
        file_put_contents($path, $yaml);
        $this->written[] = $path;
        RuntimeImageCatalog::useConfig($path);
    }

    /**
     * Resolution survives a bad config; the built image does not exist under
     * one. Both halves are asserted together because leaving only the first
     * true is the dangerous state: an image built under a name this file no
     * longer describes is fetched from a registry that never published it.
     */
    private function assertPhpStillDeploysWithoutABase(string $why): void
    {
        $this->assertSame(PhpRuntime::MINORS, PhpRuntime::minors(), $why);
        $this->assertSame(PhpRuntime::DEFAULT_MINOR, PhpRuntime::defaultMinor(), $why);
        $this->assertSame(
            'php:8.3-' . PhpRuntime::IMAGE_VARIANT,
            PhpRuntime::imageTag('8.3'),
            $why
        );
        $this->assertNull(PhpBaseImage::repository(), $why);
        $this->assertNull(PhpBaseImage::stubName(), $why);
        $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm'), $why);
    }

    public function test_a_missing_file_falls_back(): void
    {
        RuntimeImageCatalog::useConfig('/nonexistent/images.yaml');

        $this->assertPhpStillDeploysWithoutABase('a host with no config file still deploys, without a shared base');
    }

    public function test_an_empty_file_falls_back(): void
    {
        $this->config('');

        $this->assertPhpStillDeploysWithoutABase('a truncated write still deploys');
    }

    public function test_malformed_yaml_falls_back_rather_than_throwing(): void
    {
        $this->config("runtimes:\n  php:\n   default: \"8.3\"\n     versions: [\n");

        $this->assertPhpStillDeploysWithoutABase('a syntax error is the operator\'s to see, not a deploy\'s to die on');
    }

    public function test_a_file_without_a_runtimes_key_falls_back(): void
    {
        $this->config("images:\n  - name: php:8.3-apache-bookworm\n");

        $this->assertPhpStillDeploysWithoutABase('the wrong file in the right place still deploys');
    }

    public function test_a_default_outside_the_declared_versions_is_refused(): void
    {
        // The most dangerous typo in the file: it is what every project saying
        // nothing about PHP would be handed, and nothing else contradicts it.
        $this->config(<<<'YAML'
        runtimes:
          php:
            default: "8.9"
            versions: ["8.1", "8.2", "8.3"]
            image:
              from: "php:{version}-apache-bookworm"
        YAML);

        $this->assertNull(RuntimeImageCatalog::defaultVersion('php'));
        $this->assertSame(PhpRuntime::DEFAULT_MINOR, PhpRuntime::defaultMinor());
        // The versions it *did* declare are still honoured: one bad key does
        // not discard the rest of the operator's edit.
        $this->assertSame(['8.1', '8.2', '8.3'], PhpRuntime::minors());
    }

    public function test_a_from_that_is_not_an_image_reference_is_refused(): void
    {
        $this->config(<<<'YAML'
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:8.3-apache-bookworm; rm -rf /"
        YAML);

        $this->assertNull(RuntimeImageCatalog::spec('php', '8.3'));
        $this->assertSame('php:8.3-' . PhpRuntime::IMAGE_VARIANT, PhpRuntime::imageTag('8.3'));
    }

    public function test_a_stub_cannot_climb_out_of_the_template_directory(): void
    {
        $this->config(<<<'YAML'
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
              build:
                repository: panelalpha/php
                stub: ../../../../etc/passwd
        YAML);

        $this->assertNull(RuntimeImageCatalog::stub('php'));
        $this->assertNull(PhpBaseImage::stubName());
        // And so no tag: a name with no recipe behind it is worse than none.
        $this->assertNull(PhpBaseImage::tag('php:8.3-apache-bookworm'));
    }

    public function test_half_a_build_block_is_not_a_build_block(): void
    {
        // A repository with no stub names an image nothing knows how to
        // produce. Provisioning would try to rebuild it on every deploy.
        $this->config(<<<'YAML'
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
              build:
                repository: panelalpha/php
        YAML);

        $spec = RuntimeImageCatalog::spec('php', '8.3');

        $this->assertNotNull($spec);
        $this->assertFalse($spec->isBuilt());
        $this->assertNull(RuntimeImageCatalog::stub('php'));
    }

    // -------------------------------------------------------------------------
    // What the build block does when it *is* well formed
    // -------------------------------------------------------------------------

    public function test_the_repository_moves_the_built_tag(): void
    {
        $this->config($this->phpConfigWithRepository('panelalpha/php-edge'));

        $tag = (string) PhpBaseImage::tag('php:8.3-apache-bookworm');

        $this->assertStringStartsWith('panelalpha/php-edge:', $tag);
        $this->assertSame('php:8.3-apache-bookworm', PhpBaseImage::sourceImage($tag));
    }

    /**
     * Renaming the repository retires the images built under the old name
     * rather than keeping them recognised forever.
     *
     * Safe because nothing asks for the old name once this file stops naming
     * it -- {@see PhpBaseImage::tag()} produces the new one, so those images
     * are unreferenced. Losing "ours" is what lets account cleanup reclaim
     * them instead of protecting them as host infrastructure indefinitely.
     */
    public function test_renaming_the_repository_retires_the_old_images(): void
    {
        $this->config($this->phpConfigWithRepository('panelalpha/php-edge'));

        $old = 'panelalpha/php:8.3-apache-bookworm-pa' . PhpBaseImage::fingerprint();

        $this->assertNull(BuiltImage::runtimeFor($old));
        $this->assertStringStartsWith(
            'panelalpha/php-edge:',
            (string) PhpBaseImage::tag('php:8.3-apache-bookworm')
        );
    }

    public function test_a_foreign_image_is_still_not_ours(): void
    {
        $this->config($this->phpConfigWithRepository('panelalpha/php-edge'));

        $this->assertNull(PhpBaseImage::sourceImage('mysql:8.4'));
        $this->assertNull(PhpBaseImage::sourceImage('php:8.3-apache-bookworm'));
        // The name this config no longer builds under.
        $this->assertNull(PhpBaseImage::sourceImage('panelalpha/php:8.3-apache-bookworm-pa20260909'));
        // No -pa fingerprint: ours by prefix, but not a tag this class wrote.
        $this->assertNull(PhpBaseImage::sourceImage('panelalpha/php-edge:8.3-apache-bookworm'));
    }

    /**
     * Changing the stub alone does NOT move the tag, and that is the price of
     * a readable one. The fingerprint is the `recipe` date beside the stub, so
     * an operator who repoints the build and leaves the date alone has a host
     * serving the old image under a name that now promises the new recipe.
     *
     * Written as a test rather than left to the config comment because it is
     * the one way this scheme is worse than the content hash it replaced, and
     * a future reader should meet it here rather than on a customer's host.
     */
    public function test_the_tag_follows_the_declared_date_and_not_the_stub(): void
    {
        $this->config($this->phpConfigWithStub('dockerfile/php-base'));
        $before = PhpBaseImage::fingerprint();

        $this->config($this->phpConfigWithStub('dockerfile/ruby-base'));

        $this->assertSame($before, PhpBaseImage::fingerprint());
    }

    public function test_bumping_the_date_is_what_moves_the_tag(): void
    {
        $this->config($this->phpConfigWithRecipe('2026-09-09'));
        $this->assertSame('20260909', PhpBaseImage::fingerprint());

        $this->config($this->phpConfigWithRecipe('2027-03-14'));
        $this->assertSame('20270314', PhpBaseImage::fingerprint());
    }

    private function phpConfigWithRecipe(string $recipe): string
    {
        return <<<YAML
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
              build:
                repository: panelalpha/php
                stub: dockerfile/php-base
                recipe: "{$recipe}"
        YAML;
    }

    private function phpConfigWithRepository(string $repository): string
    {
        return <<<YAML
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
              build:
                repository: {$repository}
                stub: dockerfile/php-base
                recipe: "2026-09-09"
                runnable: true
        YAML;
    }

    private function phpConfigWithStub(string $stub): string
    {
        return <<<YAML
        runtimes:
          php:
            default: "8.3"
            versions: ["8.3"]
            image:
              from: "php:{version}-apache-bookworm"
              build:
                repository: panelalpha/php
                stub: {$stub}
                recipe: "2026-09-09"
                runnable: true
        YAML;
    }
}
