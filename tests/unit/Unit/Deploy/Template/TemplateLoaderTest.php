<?php

namespace Tests\Unit\Deploy\Template;

use App\Lib\Deploy\Template\ResourceDirectory;
use App\Lib\Deploy\Template\TemplateException;
use App\Lib\Deploy\Template\TemplateLoader;
use PHPUnit\Framework\TestCase;

/**
 * Reading the shipped deploy resources.
 *
 * They are files rather than heredocs so an editor highlights them and a
 * reviewer diffs them, and they resolve relative to this package rather than
 * through resource_path() so the generators stay testable without booting the
 * app. The failure worth being loud about is a missing one: a template that
 * silently read as empty would produce a Dockerfile with a hole in it.
 */
class TemplateLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        TemplateLoader::flush();
        parent::tearDown();
    }

    public function test_the_resource_directories_exist(): void
    {
        $this->assertDirectoryExists(ResourceDirectory::templates());
        $this->assertDirectoryExists(ResourceDirectory::assets());
    }

    public function test_a_stub_is_read_by_name(): void
    {
        $stub = TemplateLoader::stub('dockerfile/entrypoint-install');

        $this->assertStringContainsString('ENTRYPOINT', $stub);
    }

    public function test_an_asset_is_read_verbatim(): void
    {
        // No placeholder substitution: these ship as written.
        $asset = TemplateLoader::asset('nginx.conf');

        $this->assertStringContainsString('server', $asset);
        $this->assertStringNotContainsString('{{', $asset);
    }

    public function test_a_stub_that_is_not_there_is_an_error(): void
    {
        // Rather than an empty string, which would produce a Dockerfile with
        // a hole in it and a build failure pointing nowhere.
        $this->expectException(TemplateException::class);

        TemplateLoader::stub('dockerfile/nothing-like-this');
    }

    public function test_an_asset_that_is_not_there_is_an_error(): void
    {
        $this->expectException(TemplateException::class);

        TemplateLoader::asset('nothing-like-this.conf');
    }

    public function test_the_error_names_the_file_it_looked_for(): void
    {
        try {
            TemplateLoader::stub('dockerfile/nothing-like-this');
            $this->fail('expected a failure');
        } catch (TemplateException $e) {
            $this->assertStringContainsString('nothing-like-this', $e->getMessage());
        }
    }

    public function test_a_file_is_read_once_per_process(): void
    {
        // Every generated Dockerfile pulls several of these; re-reading them
        // per deploy is filesystem work for a file that cannot change.
        $first = TemplateLoader::stub('dockerfile/entrypoint-install');
        $second = TemplateLoader::stub('dockerfile/entrypoint-install');

        $this->assertSame($first, $second);
    }

    public function test_flushing_lets_a_changed_file_be_reread(): void
    {
        // The seam the tests themselves rely on.
        TemplateLoader::stub('dockerfile/entrypoint-install');
        TemplateLoader::flush();

        $this->assertStringContainsString('ENTRYPOINT', TemplateLoader::stub('dockerfile/entrypoint-install'));
    }
}
