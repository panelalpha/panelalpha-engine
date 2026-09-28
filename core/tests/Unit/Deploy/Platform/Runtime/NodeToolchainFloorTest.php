<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * engine#145, engine#134: the Node major is raised to what the project's own
 * toolchain needs — a pinned pnpm 11, or a direct dependency's engines.node in
 * the lockfile — instead of the engine default it cannot run on.
 */
class NodeToolchainFloorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/node-floor-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    public function test_pnpm_11_pin_raises_an_undeclared_project_to_22(): void
    {
        // Keystone 6: no engines.node, packageManager pnpm@11.21.0.
        $this->write('package.json', ['packageManager' => 'pnpm@11.21.0']);

        $requirement = $this->resolve();

        $this->assertSame('22', $requirement?->version);
        $this->assertSame('package.json packageManager pnpm@11.21.0', $requirement?->source);
        $this->assertStringStartsWith('node:22-', NodeRuntime::imageFor($this->tmpDir));
    }

    public function test_pnpm_11_pin_raises_an_open_engines_floor(): void
    {
        $this->write('package.json', ['packageManager' => 'pnpm@11.0.0', 'engines' => ['node' => '>=18']]);

        $this->assertSame('22', $this->resolve()?->version);
    }

    public function test_pnpm_11_pin_never_lowers_a_newer_declaration(): void
    {
        // Misskey: .node-version 26.4.0 with pnpm@11.25.0.
        $this->write('package.json', ['packageManager' => 'pnpm@11.25.0']);
        file_put_contents($this->tmpDir . '/.node-version', "26.4.0\n");

        $requirement = $this->resolve();

        $this->assertSame('26', $requirement?->version);
        $this->assertSame('.node-version', $requirement?->source);
    }

    public function test_pnpm_10_pin_keeps_the_default(): void
    {
        $this->write('package.json', ['packageManager' => 'pnpm@10.18.3']);

        $requirement = $this->resolve();

        $this->assertSame(NodeRuntime::defaultMajor(), $requirement?->version);
        $this->assertSame('engine default', $requirement?->source);
    }

    public function test_npm_lockfile_engines_of_a_direct_dependency_raise_the_default(): void
    {
        // start-technologies: Angular 22, nothing declared.
        $angular = '^22.22.3 || ^24.15.0 || >=26.0.0';
        $this->write('package.json', ['dependencies' => ['@angular/core' => '^22.0.0', 'rxjs' => '^7.8.0']]);
        $this->write('package-lock.json', [
            'lockfileVersion' => 3,
            'packages' => [
                '' => ['dependencies' => ['@angular/core' => '^22.0.0']],
                'node_modules/@angular/core' => ['version' => '22.0.2', 'engines' => ['node' => $angular]],
                'node_modules/rxjs' => ['version' => '7.8.1'],
                // Transitive: must not count.
                'node_modules/foo/node_modules/bar' => ['engines' => ['node' => '>=24']],
                'node_modules/unlisted' => ['engines' => ['node' => '>=24']],
            ],
        ]);

        $requirement = $this->resolve();

        $this->assertSame('22', $requirement?->version);
        $this->assertSame('@angular/core engines.node in package-lock.json', $requirement?->source);
        $this->assertSame($angular, $requirement?->constraint);
        $this->assertStringStartsWith('node:22-', NodeRuntime::imageFor($this->tmpDir));
    }

    public function test_pnpm_lockfile_engines_are_read_in_v9_and_v6_shapes(): void
    {
        $this->write('package.json', ['devDependencies' => ['vitest' => '^3', 'left-pad' => '^1']]);
        file_put_contents($this->tmpDir . '/pnpm-lock.yaml', <<<'YAML'
lockfileVersion: '9.0'

importers:
  .:
    devDependencies:
      vitest:
        specifier: ^3
        version: 3.2.0

packages:

  left-pad@1.3.0:
    resolution: {integrity: sha512-x}
    engines: {node: '>=10'}

  vitest@3.2.0:
    resolution: {integrity: sha512-y}
    engines: {node: ^22.12.0 || ^24.0.0 || >=26.0.0}
    hasBin: true

snapshots:

  vitest@3.2.0:
    engines: {node: '>=99'}
YAML);

        $requirement = $this->resolve();

        $this->assertSame('22', $requirement?->version);
        $this->assertSame('vitest engines.node in pnpm-lock.yaml', $requirement?->source);
    }

    public function test_pnpm_lockfile_v6_scoped_header(): void
    {
        $this->write('package.json', ['dependencies' => ['@scope/pkg' => '^2']]);
        file_put_contents($this->tmpDir . '/pnpm-lock.yaml', <<<'YAML'
lockfileVersion: '6.0'

packages:

  /@scope/pkg@2.0.0:
    resolution: {integrity: sha512-z}
    engines: {node: '>=24'}
    dev: false
YAML);

        $this->assertSame('24', $this->resolve()?->version);
    }

    public function test_dependency_engines_never_override_a_declared_version(): void
    {
        $this->write('package.json', ['engines' => ['node' => '^20'], 'dependencies' => ['x' => '1']]);
        $this->write('package-lock.json', [
            'packages' => ['node_modules/x' => ['engines' => ['node' => '>=24']]],
        ]);

        $requirement = $this->resolve();

        $this->assertSame('20', $requirement?->version);
        $this->assertSame('package.json engines.node', $requirement?->source);
    }

    public function test_dependency_floors_below_the_default_leave_the_default(): void
    {
        $this->write('package.json', ['dependencies' => ['x' => '1']]);
        $this->write('package-lock.json', [
            'packages' => ['node_modules/x' => ['engines' => ['node' => '>=14']]],
        ]);

        $requirement = $this->resolve();

        $this->assertSame(NodeRuntime::defaultMajor(), $requirement?->version);
        $this->assertSame('engine default', $requirement?->source);
    }

    private function resolve(): ?\App\Lib\Deploy\Platform\Runtime\Requirement
    {
        return RuntimeRegistry::get('node')->resolve(ProjectContext::at($this->tmpDir));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $name, array $data): void
    {
        file_put_contents($this->tmpDir . '/' . $name, json_encode($data));
    }
}
