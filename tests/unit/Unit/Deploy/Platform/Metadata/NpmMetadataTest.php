<?php

namespace Tests\Unit\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Metadata\NpmMetadata;

class NpmMetadataTest extends MetadataTestCase
{
    public function test_it_reads_identity_from_package_json(): void
    {
        $this->writeJson('package.json', [
            'name' => 'shop-web',
            'version' => '2.1.0',
            'description' => 'Storefront',
            'private' => true,
            'license' => 'MIT',
            'repository' => ['type' => 'git', 'url' => 'https://github.com/acme/shop'],
            'author' => 'Jane Doe <jane@acme.test>',
            'scripts' => ['dev' => 'next dev', 'build' => 'next build'],
            'dependencies' => ['next' => '^15.0.0', 'react' => '^19.0.0'],
            'devDependencies' => ['typescript' => '^5.0.0'],
        ]);

        $package = (new NpmMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame('node', $package->ecosystem);
        $this->assertSame('shop-web', $package->name);
        $this->assertSame('2.1.0', $package->version);
        $this->assertTrue($package->private);
        $this->assertSame('https://github.com/acme/shop', $package->repository);
        $this->assertSame(['Jane Doe <jane@acme.test>'], $package->authors);
        $this->assertSame(['dev', 'build'], $package->scripts);
        $this->assertSame(['dependencies' => 2, 'devDependencies' => 1], $package->dependencyCounts);
    }

    public function test_engines_are_read_as_the_platform(): void
    {
        $this->writeJson('package.json', [
            'name' => 'shop-web',
            'engines' => ['Node' => '>=20', 'pnpm' => '9.x'],
        ]);

        $package = (new NpmMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame(['node' => '>=20', 'pnpm' => '9.x'], $package->platform);
    }

    public function test_a_package_json_without_engines_declares_no_platform(): void
    {
        $this->writeJson('package.json', ['name' => 'shop-web']);

        $package = (new NpmMetadata())->read($this->context());

        $this->assertNotNull($package);
        $this->assertSame([], $package->platform);
    }

    public function test_the_meta_framework_outranks_the_view_library(): void
    {
        $this->writeJson('package.json', [
            'dependencies' => ['react' => '^19.0.0', 'next' => '^15.0.0'],
        ]);

        $package = (new NpmMetadata())->read($this->context());

        $this->assertNotNull($package);
        // Every Next.js project also depends on React. Reporting React first
        // would describe the wrong thing.
        $this->assertSame('Next.js', $package->framework()?->name);
        $this->assertSame(['Next.js', 'React'], array_column(
            array_map(static fn ($f) => $f->toArray(), $package->frameworks),
            'name'
        ));
    }

    public function test_versions_are_resolved_from_a_v3_lockfile(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['next' => '^15.0.0']]);
        $this->writeJson('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'shop', 'version' => '1.0.0'],
                'node_modules/next' => ['version' => '15.0.3'],
            ],
        ]);

        $framework = (new NpmMetadata())->read($this->context())?->framework();

        $this->assertNotNull($framework);
        $this->assertSame('15.0.3', $framework->version);
        $this->assertSame('package-lock.json', $framework->source);
    }

    public function test_versions_are_resolved_from_a_v1_lockfile(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['express' => '^4.0.0']]);
        $this->writeJson('package-lock.json', [
            'lockfileVersion' => 1,
            'dependencies' => ['express' => ['version' => '4.19.2']],
        ]);

        $this->assertSame(
            '4.19.2',
            (new NpmMetadata())->read($this->context())?->framework()?->version
        );
    }

    public function test_a_pnpm_project_reports_the_range_rather_than_a_guess(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['nuxt' => '^3.14.0']]);
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $framework = (new NpmMetadata())->read($this->context())?->framework();

        $this->assertNotNull($framework);
        $this->assertNull($framework->version);
        $this->assertSame('^3.14.0', $framework->constraint);
        $this->assertSame('package.json', $framework->source);
    }

    public function test_it_reads_workspaces_in_both_shapes(): void
    {
        $this->writeJson('package.json', ['workspaces' => ['apps/*', 'packages/*']]);
        $this->assertSame(['apps/*', 'packages/*'], (new NpmMetadata())->read($this->context())?->workspaces);

        $this->writeJson('package.json', ['workspaces' => ['packages' => ['apps/*']]]);
        $this->assertSame(['apps/*'], (new NpmMetadata())->read($this->context())?->workspaces);
    }

    public function test_no_package_json_is_no_node_package(): void
    {
        $this->assertNull((new NpmMetadata())->read($this->context()));
    }

    public function test_a_nested_copy_does_not_shadow_the_top_level_install(): void
    {
        // Matomo asks for Vite 6 and installs 6.4.3; vitest drags in its own
        // Vite 5 underneath. Reporting the nested one would say the project
        // builds on a major version it explicitly did not ask for.
        $this->writeJson('package.json', ['devDependencies' => ['vite' => '^6.3.5']]);
        $this->writeJson('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['name' => 'app', 'version' => '1.0.0'],
                'node_modules/vite' => ['version' => '6.4.3'],
                'node_modules/vitest/node_modules/vite' => ['version' => '5.4.21'],
            ],
        ]);

        $this->assertSame(
            '6.4.3',
            (new NpmMetadata())->read($this->context())?->framework()?->version
        );
    }

    public function test_a_scoped_package_keeps_its_scope(): void
    {
        $this->writeJson('package.json', ['dependencies' => ['@nestjs/core' => '^10.0.0']]);
        $this->writeJson('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => ['node_modules/@nestjs/core' => ['version' => '10.4.1']],
        ]);

        $framework = (new NpmMetadata())->read($this->context())?->framework();

        $this->assertSame('NestJS', $framework?->name);
        $this->assertSame('10.4.1', $framework?->version);
    }
}
