<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\BuiltImage;
use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\CacheManager\ImageCatalog;
use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\Platform\Runtime\Python\SystemPackages as PythonSystemPackages;
use App\Lib\Deploy\Platform\Runtime\Ruby\Gemfile;
use App\Lib\Deploy\Platform\Runtime\Ruby\SystemPackages as RubySystemPackages;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use PHPUnit\Framework\TestCase;

/**
 * A catalogue entry naming `packages:` is one of our built bases, and it must be the very tag a deploy of a
 * project needing those packages computes, or prewarm and the publish script make an image nobody asks for.
 */
class CatalogueBuiltVariantsTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/catalogue-variants-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->file, <<<'YAML'
runtimes:
  php:
    default: "8.3"
    image:
      from: "php:{version}-apache-bookworm"
      build: {repository: panelalpha/php, stub: dockerfile/php-base, recipe: "2026-10-07", runnable: true}
    versions:
      - version: "8.3"
        prewarm: 870
      - version: "8.3"
        packages: ["ffmpeg"]
        prewarm: 701
  python:
    default: "3.12"
    image:
      from: "python:{version}-slim"
      build: {repository: panelalpha/python, stub: dockerfile/python-base, runnable: true}
    versions:
      - version: "3.12"
        prewarm: 760
      - version: "3.12"
        packages: ["libpq-dev"]
        prewarm: 702
      - version: "3.12"
        packages: ["default-libmysqlclient-dev"]
        prewarm: 703
  ruby:
    default: "3.3"
    image:
      from: "ruby:{version}-slim-bookworm"
      build: {repository: panelalpha/ruby, stub: dockerfile/ruby-base, runnable: false}
    versions:
      - version: "3.3"
        prewarm: 740
      - version: "3.3"
        packages: ["libpq-dev"]
        prewarm: 704
YAML);
        RuntimeImageCatalog::useConfig($this->file);
        ImageCatalog::useConfig($this->file);
    }

    protected function tearDown(): void
    {
        RuntimeImageCatalog::useConfig(null);
        ImageCatalog::useConfig(null);
        @unlink($this->file);
    }

    public function test_a_python_entry_is_the_tag_a_deploy_of_a_project_needing_those_packages_computes(): void
    {
        $deploy = PythonBaseImage::tag('python:3.12-slim', PythonSystemPackages::for(["django\npsycopg2-binary\n"]));

        $this->assertSame($deploy, $this->item('python:3.12+libpq-dev')['ref']);
        $this->assertSame(
            PythonBaseImage::tag('python:3.12-slim', PythonSystemPackages::for(["mysqlclient\n"])),
            $this->item('python:3.12+default-libmysqlclient-dev')['ref']
        );
    }

    public function test_a_ruby_entry_is_the_tag_a_deploy_of_a_matching_gemfile_computes(): void
    {
        $gemfile = Gemfile::fromContents("source 'https://rubygems.org'\ngem 'rails'\ngem 'pg'\n");

        $this->assertSame(
            RubyBaseImage::tag('ruby:3.3-slim-bookworm', RubySystemPackages::for($gemfile)),
            $this->item('ruby:3.3+libpq-dev')['ref']
        );
    }

    public function test_a_php_entry_with_packages_is_the_tag_a_recipe_with_system_packages_computes(): void
    {
        $this->assertSame(
            PhpBaseImage::tag('php:8.3-apache-bookworm', [], ['ffmpeg']),
            $this->item('php:8.3+ffmpeg')['ref']
        );
    }

    public function test_a_plain_python_or_ruby_entry_stays_the_stock_image(): void
    {
        $this->assertSame(['python:3.12-slim', HostPrewarmPlan::KIND_PULL], $this->refAndKind('python:3.12'));
        $this->assertSame(['ruby:3.3-slim-bookworm', HostPrewarmPlan::KIND_PULL], $this->refAndKind('ruby:3.3'));
    }

    public function test_every_built_entry_has_a_dockerfile_to_build_it_from(): void
    {
        foreach (HostPrewarmPlan::available() as $item) {
            if ($item['kind'] !== HostPrewarmPlan::KIND_BUILD) {
                continue;
            }
            $this->assertNotNull(
                BuiltImage::dockerfile($item['ref'], $item['extensions'], $item['packages']),
                "{$item['id']} names {$item['ref']} but nothing here can build it"
            );
        }
    }

    /** @return array<string, mixed> */
    private function item(string $id): array
    {
        foreach (HostPrewarmPlan::available() as $item) {
            if ($item['id'] === $id) {
                $this->assertSame(HostPrewarmPlan::KIND_BUILD, $item['kind'], $id);

                return $item;
            }
        }
        $this->fail("no catalogue entry {$id}");
    }

    /** @return array{string, string} */
    private function refAndKind(string $id): array
    {
        foreach (HostPrewarmPlan::available() as $item) {
            if ($item['id'] === $id) {
                return [$item['ref'], $item['kind']];
            }
        }
        $this->fail("no catalogue entry {$id}");
    }
}
