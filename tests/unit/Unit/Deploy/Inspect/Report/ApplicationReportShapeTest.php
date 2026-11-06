<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Findings\Finding;
use App\Lib\Deploy\Inspect\Report\StageSchedule;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\Runtime\Requirement;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The whole application section, compared with assertSame so its key order is pinned
 * along with every value; the recipe registry's own answers are asked for, not copied.
 */
class ApplicationReportShapeTest extends ReportTestCase
{
    /**
     * @param array<string, string> $files
     * @param \Closure(string): array<string, mixed> $decision
     * @param array<string, mixed> $expected
     */
    #[DataProvider('reports')]
    public function test_the_whole_section_is_pinned(array $files, \Closure $decision, ?string $issue, ?string $origin, array $expected): void
    {
        foreach ($files as $file => $contents) {
            $this->write($file, $contents);
        }

        $finding = $issue === null ? null : new Finding(AppInspector::DETECTION_FAILED, $issue);
        $report = (new ApplicationReport($this->tmpDir . '/', $this->context(), $decision($this->tmpDir), $origin, null, $finding))->build();

        $expected['candidates'] = PlatformCandidates::forContext($this->context());
        $expected['stages'] = StageSchedule::of($this->context(), $decision($this->tmpDir));
        $this->assertSame($expected, $report);
    }

    public static function reports(): array
    {
        $expected = self::expected();
        $cases = [];
        foreach (self::cases() as $name => $case) {
            $cases[$name] = [...$case, $expected[$name]];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: \Closure, 2: ?string, 3: ?string}>
     */
    private static function cases(): array
    {
        return [
            'a node project with every field set' => [
                ['package.json' => '{"name":"app","scripts":{"build":"vite build","start":"node server.js"}}', 'docker-compose.yml' => "services: {}\n"],
                static fn (string $dir): array => [
                    'strategy' => Strategies::FALLBACK,
                    'label' => 'Node.js',
                    'platform' => 'node',
                    'runtime' => 'node',
                    'image' => 'node:22-bookworm-slim',
                    'output_directory' => 'dist',
                    'dockerfile' => 'Dockerfile',
                    'build_args' => ['BASE' => 'apache', 'N' => 3, '' => 'x', 'BAD' => ['no'], 7 => 'int key'],
                    'database' => 'mysql',
                    'compose_path' => $dir.'/docker-compose.yml',
                    'static_index' => 'index.html',
                    'install_command' => 'npm ci',
                    'build_command' => '  ',
                    'start_command' => 'node server.js',
                    'requirements' => [new Requirement('node', '22', '>=20', 'package.json engines.node', 'runtime'), 'junk'],
                ],
                'Repository could not be cloned.',
                'panelalpha.yaml',
            ],
            'a bare decision' => [
                [],
                static fn (string $dir): array => ['strategy' => Strategies::FALLBACK, 'label' => 'Unknown', 'requirements' => 'junk', 'build_args' => 'junk'],
                null,
                null,
            ],
            'a node project the node recipe claims' => [
                ['package.json' => '{"name":"app","scripts":{"build":"vite build","start":"node server.js"},"dependencies":{"express":"^4"}}', 'server.js' => 'require("express")().listen(3000);'],
                static fn (string $dir): array => ['strategy' => 'node', 'label' => 'Node.js', 'start_command' => 'node server.js', 'package_manager' => ''],
                null,
                null,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expected(): array
    {
        return [
            'a node project with every field set' => [
                'strategy' => 'fallback',
                'platform' => 'node',
                'label' => 'Node.js',
                'runtime' => 'node',
                'image' => 'node:22-bookworm-slim',
                'package_manager' => 'npm',
                'output_directory' => 'dist',
                'dockerfile' => 'Dockerfile',
                'build_args' => [
                    'BASE' => 'apache',
                    'N' => '3',
                ],
                'database' => 'mysql',
                'compose_file' => 'docker-compose.yml',
                'static_index' => 'index.html',
                'app_config' => 'panelalpha.yaml',
                'candidates' => '<candidates>',
                'verdict' => 'not_deployable',
                'deployable' => false,
                'issue' => ['code' => AppInspector::DETECTION_FAILED, 'message' => 'Repository could not be cloned.'],
                'toolchain' => [
                    [
                        'id' => 'node',
                        'version' => '22',
                        'constraint' => '>=20',
                        'source' => 'package.json engines.node',
                        'role' => 'runtime',
                    ],
                ],
                'commands' => [
                    'install' => 'npm ci',
                    'build' => null,
                    'start' => 'node server.js',
                ],
                'stages' => [],
            ],
            'a bare decision' => [
                'strategy' => 'fallback',
                'platform' => null,
                'label' => 'Unknown',
                'runtime' => null,
                'image' => null,
                'package_manager' => null,
                'output_directory' => null,
                'dockerfile' => null,
                'build_args' => [],
                'database' => null,
                'compose_file' => null,
                'static_index' => null,
                'app_config' => null,
                'candidates' => [],
                'verdict' => 'not_deployable',
                'deployable' => false,
                'issue' => ['code' => DeployabilityCheck::PROJECT_EMPTY, 'message' => 'Project directory is empty; nothing to deploy.'],
                'toolchain' => [],
                'commands' => [
                    'install' => null,
                    'build' => null,
                    'start' => null,
                ],
                'stages' => [],
            ],
            'a node project the node recipe claims' => [
                'strategy' => 'node',
                'platform' => null,
                'label' => 'Node.js',
                'runtime' => null,
                'image' => null,
                'package_manager' => 'npm',
                'output_directory' => null,
                'dockerfile' => null,
                'build_args' => [],
                'database' => null,
                'compose_file' => null,
                'static_index' => null,
                'app_config' => null,
                'candidates' => '<candidates>',
                'verdict' => 'deployable',
                'deployable' => true,
                'issue' => null,
                'toolchain' => [],
                'commands' => [
                    'install' => null,
                    'build' => null,
                    'start' => 'node server.js',
                ],
                'stages' => '<stages>',
            ],
        ];
    }
}
