<?php

namespace Tests\Unit\Deploy\Inspect;

use App\Lib\Deploy\Inspect\DetectionFiles;
use App\Lib\Deploy\Platform\PlatformCommand;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\Probes\ProbeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A tree inspection fetches only what DetectionFiles picks and leaves every
 * other file empty, so a file detection reads that is not picked reads as "no
 * match" and inspect answers differently from a clone.
 */
class DetectionFilesTest extends TestCase
{
    /**
     * What each probe opens. A probe added without an entry here fails
     * test_every_probe_has_its_files_listed, so its files get considered.
     */
    private const PROBE_FILES = [
        'angular-output' => ['angular.json'],
        'astro-ssr' => ['astro.config.mjs', 'package.json'],
        'bundler-spa' => ['package.json', 'webpack.config.js'],
        'composer-metadata-only' => ['composer.json'],
        'compose-usable' => ['compose.yaml', 'docker-compose.yml', '.env', 'docker/Dockerfile'],
        'compose-usable-nested' => ['docker/docker-compose.yml', 'deploy/compose.yaml', 'console/compose.yml'],
        'django' => ['manage.py', 'src/manage.py', 'netbox/netbox/wsgi.py'],
        'dockerfile' => [
            'Dockerfile', 'Dockerfile.prod', 'Containerfile', 'docker/Dockerfile',
            'scripts/docker/Dockerfile', 'build/Dockerfile', 'app.dockerfile',
        ],
        'dockerfile-named' => ['php.dockerfile', 'app.dockerfile'],
        'dotnet-project' => ['App.sln', 'App.csproj', 'src/App/App.csproj', 'global.json', 'Directory.Build.props'],
        'html-site' => [],
        'next-export' => ['next.config.mjs', 'package.json'],
        'next-workspace' => ['next.config.js', 'apps/web/package.json', 'apps/web/next.config.ts', 'web/package.json'],
        'not-a-web-app' => ['package.json'],
        'php-sources' => [],
        'procfile-web' => ['Procfile'],
        'pyproject' => ['pyproject.toml'],
        'python-nothing-to-start' => ['requirements.txt', 'pyproject.toml', 'Pipfile', 'main.py', 'SABnzbd.py'],
        'static-entry' => [],
        'sveltekit-static-adapter' => ['svelte.config.js'],
        'vite-not-the-build' => ['vite.config.ts', 'package.json'],
    ];

    /**
     * @param list<string> $paths
     * @return list<array{path: string, type: string, mode: string, size: int}>
     */
    private static function tree(array $paths, string $mode = '100644'): array
    {
        return array_map(
            static fn (string $p): array => ['path' => $p, 'type' => 'blob', 'mode' => $mode, 'size' => 10],
            $paths
        );
    }

    public function test_every_probe_has_its_files_listed(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(ProbeRegistry::all()), array_keys(self::PROBE_FILES));
    }

    public function test_every_file_a_probe_reads_is_fetched(): void
    {
        foreach (self::PROBE_FILES as $probe => $files) {
            foreach ($files as $file) {
                $this->assertSame(
                    [$file],
                    DetectionFiles::select(self::tree([$file]))['decisive'],
                    "probe {$probe} reads {$file}"
                );
            }
        }
    }

    public function test_every_file_a_manifest_content_rule_reads_is_fetched(): void
    {
        $files = [];
        foreach (PlatformRegistry::all() as $manifest) {
            self::contentFiles($manifest->detect, $files);
            foreach ($manifest->commands as $command) {
                if ($command instanceof PlatformCommand && $command->when !== null) {
                    self::contentFiles($command->when, $files);
                }
            }
        }

        $this->assertNotEmpty($files);
        foreach (array_keys($files) as $file) {
            $this->assertSame([$file], DetectionFiles::select(self::tree([$file]))['decisive'], $file);
        }
    }

    /**
     * The files a detect or when node reads the contents of.
     *
     * @param array<string, mixed> $node
     * @param array<string, true> $files
     */
    private static function contentFiles(array $node, array &$files): void
    {
        foreach ($node as $key => $value) {
            if (in_array($key, ['all', 'any', 'none'], true) && is_array($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        self::contentFiles($child, $files);
                    }
                }
            } elseif ($key === 'not' && is_array($value)) {
                self::contentFiles($value, $files);
            } elseif ($key === 'dep' || $key === 'script') {
                $files['package.json'] = true;
            } elseif ($key === 'composer') {
                $files['composer.json'] = true;
            } elseif ($key === 'contains' && is_array($value)) {
                if (is_string($value['path'] ?? null)) {
                    $files[$value['path']] = true;
                } elseif (is_string($value['glob'] ?? null)) {
                    foreach (['js', 'mjs', 'ts', 'cjs'] as $extension) {
                        $files[$value['glob'] . '.' . $extension] = true;
                    }
                }
            }
        }
    }

    public function test_runtime_manifests_versions_and_environment_at_the_root_are_fetched(): void
    {
        $files = [
            'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'pnpm-workspace.yaml',
            'composer.json', 'composer.lock', 'Gemfile', 'Gemfile.lock', 'go.mod', 'Cargo.toml',
            'Cargo.lock', 'pom.xml', 'build.gradle.kts', 'requirements.txt', 'requirements-prod.txt',
            'setup.py', 'mix.exs', 'deno.json', '.nvmrc', '.node-version', '.python-version',
            '.ruby-version', '.tool-versions', 'runtime.txt', '.env', '.env.example', 'example.env',
            '.dockerignore', 'panelalpha.yaml', '.panelalpha/config.yaml', '.panelalpha/files/x.php',
            '.cargo/config.toml', 'nest-cli.json', 'gulpfile.js',
        ];

        $this->assertEqualsCanonicalizing($files, DetectionFiles::select(self::tree($files))['decisive']);
    }

    public function test_a_monorepo_package_is_read_only_where_detection_looks_for_one(): void
    {
        $picked = DetectionFiles::select(self::tree([
            'package.json',
            'server/package.json',
            'apps/web/package.json',
            'packages/ui/package.json',
            'playground/demo/package.json',
            'examples/basic/Cargo.toml',
            'packages/@scope/thing/package.json',
        ]))['decisive'];

        $this->assertSame(
            ['package.json', 'server/package.json', 'apps/web/package.json', 'packages/ui/package.json'],
            $picked
        );
    }

    public function test_dependencies_and_dot_directories_are_not_searched(): void
    {
        $picked = DetectionFiles::select(self::tree([
            'node_modules/x/package.json',
            'vendor/y/composer.json',
            '.github/workflows/Dockerfile',
            '.docker/compose.yml',
            'a/b/c/d/Dockerfile',
        ]))['decisive'];

        $this->assertSame(['.docker/compose.yml'], $picked);
    }

    public function test_root_python_scripts_are_read_and_deeper_ones_are_not(): void
    {
        $picked = DetectionFiles::select(self::tree(['SABnzbd.py', 'tools/build.py', 'app/main.py']))['decisive'];

        $this->assertSame(['SABnzbd.py', 'app/main.py'], $picked);
    }

    public function test_go_sources_refine_only_beside_a_go_mod_one_per_directory(): void
    {
        $sources = ['main.go', 'util.go', 'cmd/app/main.go', 'cmd/app/flags.go', 'pkg/x/a_test.go', 'internal/a/b/c.go'];

        $this->assertSame([], DetectionFiles::select(self::tree($sources))['refining']);
        $this->assertSame(
            ['main.go', 'cmd/app/main.go'],
            DetectionFiles::select(self::tree(['go.mod', ...$sources]))['refining']
        );
    }

    public function test_links_are_listed_apart_from_the_files_to_fetch(): void
    {
        $entries = [...self::tree(['compose.yml'], DetectionFiles::SYMLINK_MODE), ...self::tree(['docker/compose.yml'])];
        $picked = DetectionFiles::select($entries);

        $this->assertSame(['docker/compose.yml'], $picked['decisive']);
        $this->assertSame(['compose.yml'], $picked['links']);
    }

    public function test_a_subdirectory_is_the_root_it_is_measured_from(): void
    {
        $picked = DetectionFiles::select(self::tree([
            'package.json',
            'services/api/package.json',
            'services/api/src/lib/deep/package.json',
            'services/api/Dockerfile',
        ]), 'services/api');

        $this->assertSame(['services/api/Dockerfile', 'services/api/package.json'], $picked['decisive']);
    }

    public function test_the_projects_a_root_solution_lists_below_the_search_depth_are_read_in_a_second_round(): void
    {
        $solution = "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Web\", \"src\\blazor\\admin\\Web\\Web.csproj\", \"{1}\"\n"
            . "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Api\", \"src\\Api\\Api.csproj\", \"{2}\"\n"
            . "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Gone\", \"src\\a\\b\\Gone\\Gone.csproj\", \"{3}\"\n"
            . "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Out\", \"..\\..\\x\\y\\z\\Out.csproj\", \"{4}\"\n"
            . "Project(\"{FAE04EC0-301F-11D3-BF4B-00C04F79EFBC}\") = \"Link\", \"src\\a\\b\\Link\\Link.csproj\", \"{5}\"\n";
        $entries = [
            ...self::tree(['App.sln', 'src/Api/Api.csproj', 'src/blazor/admin/Web/Web.csproj', 'src/blazor/admin/Web/Program.cs']),
            ...self::tree(['src/a/b/Link/Link.csproj'], DetectionFiles::SYMLINK_MODE),
        ];
        $files = DetectionFiles::select($entries);

        $this->assertSame(['App.sln', 'src/Api/Api.csproj'], $files['decisive']);
        $this->assertSame(
            ['src/blazor/admin/Web/Web.csproj'],
            DetectionFiles::solutionListed($entries, $files, null, static fn (string $path): ?string => $path === 'App.sln' ? $solution : null)
        );
    }

    public function test_the_directory_build_props_a_listed_project_inherits_from_are_read_with_it(): void
    {
        $slnx = '<Solution><Project Path="src/blazor/admin/Web/Web.csproj" /><Project Path="src/Gone/a/b/Gone.csproj" /></Solution>';
        $entries = self::tree([
            'App.slnx',
            'Directory.Build.props',
            'src/Directory.Build.props',
            'src/blazor/admin/Directory.Build.props',
            'src/blazor/admin/Web/Directory.Build.props',
            'src/blazor/admin/Web/Web.csproj',
            'src/blazor/admin/Other/Directory.Build.props',
            'src/Gone/a/b/Directory.Build.props',
        ]);
        $files = DetectionFiles::select($entries);

        $this->assertSame(
            ['src/blazor/admin/Web/Directory.Build.props', 'src/blazor/admin/Web/Web.csproj'],
            DetectionFiles::solutionListed($entries, $files, null, static fn (string $path): ?string => $slnx)
        );
    }

    public function test_only_a_solution_at_the_inspected_root_is_read_for_its_projects(): void
    {
        $slnx = '<Solution><Project Path="a/b/c/Web/Web.csproj" /></Solution>';
        $entries = self::tree([
            'Root.slnx',
            'services/api/Api.slnx',
            'services/api/a/b/c/Web/Web.csproj',
            'services/api/tools/Nested.slnx',
            'a/b/c/Web/Web.csproj',
        ]);
        $read = static fn (string $path): ?string => $slnx;

        $this->assertSame(
            ['services/api/a/b/c/Web/Web.csproj'],
            DetectionFiles::solutionListed($entries, DetectionFiles::select($entries, 'services/api'), 'services/api', $read)
        );
        $this->assertSame(
            ['a/b/c/Web/Web.csproj'],
            DetectionFiles::solutionListed($entries, DetectionFiles::select($entries), null, $read)
        );
    }

    public function test_shallow_files_come_first(): void
    {
        $picked = DetectionFiles::select(self::tree(['docker/Dockerfile', 'Dockerfile', 'a/b/Dockerfile']))['decisive'];

        $this->assertSame(['Dockerfile', 'docker/Dockerfile', 'a/b/Dockerfile'], $picked);
    }
}
