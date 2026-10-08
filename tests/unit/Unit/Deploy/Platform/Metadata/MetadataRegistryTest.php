<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\Metadata\PackageMetadata;

class MetadataRegistryTest extends MetadataTestCase
{
    public function test_every_reader_in_the_directory_is_registered(): void
    {
        $readers = MetadataRegistry::all();

        foreach ($readers as $id => $reader) {
            $this->assertInstanceOf(PackageMetadata::class, $reader);
            $this->assertSame($id, $reader->id());
        }

        // Discovery is by convention, so a new file is a new reader with no
        // list to update. These are the ones that exist today.
        $this->assertSame(['go', 'java', 'node', 'php', 'python', 'rust'], array_keys($readers));
    }

    public function test_a_polyglot_project_reports_every_ecosystem(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'acme/shop',
            'require' => ['laravel/framework' => '^11.0'],
        ]);
        $this->writeJson('package.json', ['name' => 'shop-web', 'dependencies' => ['vite' => '^6.0']]);

        $packages = MetadataRegistry::read($this->context());

        $this->assertSame(['node', 'php'], array_map(
            static fn (AppPackage $p): string => $p->ecosystem,
            $packages
        ));
    }

    public function test_the_platform_runtime_picks_the_primary(): void
    {
        $this->writeJson('composer.json', ['name' => 'acme/shop']);
        $this->writeJson('package.json', ['name' => 'shop-web']);

        $report = MetadataRegistry::describe($this->context(), 'php');

        // A Laravel app is a PHP application that happens to compile assets.
        $this->assertSame('acme/shop', $report['name']);
        $this->assertSame('composer.json', $report['source']);
        $this->assertCount(2, $report['packages']);
        // The primary leads, so packages[0] is never the asset pipeline.
        $this->assertSame('php', $report['packages'][0]['ecosystem']);
    }

    public function test_without_a_runtime_a_named_package_beats_an_unnamed_one(): void
    {
        // package.json comes first alphabetically by ecosystem (node < php),
        // but it is the one with no name of its own.
        $this->writeJson('package.json', ['dependencies' => ['vite' => '^6.0']]);
        $this->writeJson('composer.json', ['name' => 'acme/shop']);

        $report = MetadataRegistry::describe($this->context());

        $this->assertSame('acme/shop', $report['name']);
    }

    public function test_an_unknown_runtime_does_not_lose_the_packages(): void
    {
        $this->writeJson('composer.json', ['name' => 'acme/shop']);

        $report = MetadataRegistry::describe($this->context(), 'static');

        $this->assertSame('acme/shop', $report['name']);
        $this->assertCount(1, $report['packages']);
    }

    public function test_a_directory_with_no_package_files_reports_nulls(): void
    {
        $report = MetadataRegistry::describe($this->context(), 'static');

        $this->assertNull($report['name']);
        $this->assertNull($report['framework']);
        $this->assertNull($report['source']);
        $this->assertSame([], $report['packages']);
    }

    public function test_the_primary_framework_is_promoted_to_the_top_level(): void
    {
        $this->writeJson('composer.json', ['require' => ['laravel/framework' => '^11.0']]);
        $this->writeJson('composer.lock', [
            'packages' => [['name' => 'laravel/framework', 'version' => 'v11.31.0']],
        ]);

        $report = MetadataRegistry::describe($this->context(), 'php');

        $this->assertSame('Laravel', $report['framework']['name']);
        $this->assertSame('11.31.0', $report['framework']['version']);
    }
}
