<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DindDeployStrategyTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-strategy-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_dind_exposes_deploy_strategy_collaborator(): void
    {
        $project = $this->dind($this->dindModel(['deploy_strategy' => 'static']));

        $this->assertInstanceOf(DeployStrategy::class, $project->strategy());
        $this->assertSame($project->strategy(), $project->strategy());
    }

    public function test_apply_static_writes_hosting_artifacts_without_container_start(): void
    {
        $projectDir = $this->homeRoot . '/alice/project';
        file_put_contents($projectDir . '/index.html', '<!DOCTYPE html><html><head><title>Hi</title></head><body></body></html>');

        $decision = DetectProjectStrategy::detect($projectDir);
        $this->assertSame(Strategies::STATIC, $decision['strategy']);

        $project = $this->dind($this->dindModel());
        $project->strategy()->apply($decision, null, $projectDir, null, 'fixture');

        // The engine's compose file has its own name, so a repository's docker-compose.yml is never overwritten.
        $this->assertFileExists($projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertFileDoesNotExist($projectDir . '/docker-compose.yml');
        $this->assertFileExists($projectDir . '/' . NginxConfig::FILENAME);
        $compose = file_get_contents($projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
        $this->assertIsString($compose);
        $this->assertStringContainsString('nginx', strtolower($compose));
    }

    /**
     * Strategy selection for representative fixtures matches Lib detection output
     * (differential seam — writers share the same DetectProjectStrategy input).
     */
    public function test_detection_decisions_match_lib_for_representative_fixtures(): void
    {
        $fixtures = [
            'static' => ['index.html' => '<html></html>'],
            'express' => [
                'package.json' => '{"name":"x","scripts":{"start":"node server.js"}}',
            ],
        ];

        foreach ($fixtures as $label => $files) {
            $dir = $this->makeFixtureDir($label, $files);
            $decision = DetectProjectStrategy::detect($dir);
            $this->assertNotSame(
                Strategies::FALLBACK,
                $decision['strategy'],
                "fixture {$label} should not fall through to fallback"
            );
            $this->assertSame(
                $decision['strategy'],
                DetectProjectStrategy::detect($dir)['strategy'],
                "fixture {$label} detection must be stable"
            );
            $this->removeTree($dir);
        }
    }

    public function test_system_apply_dispatcher_branch_order(): void
    {
        $systemBody = $this->applyMethodBody(DeployStrategy::class);

        $this->assertStringContainsString('prepare()->run(', $systemBody);
        $this->assertStringContainsString('userCompose()->apply(', $systemBody);
        $this->assertStringContainsString('Strategies::STATIC', $systemBody);

        // Compose apply must run before prepare so user-compose wins the
        // dispatcher when both would match — same guard PrepareStageReachabilityTest.
        $composeAt = strpos($systemBody, 'userCompose()->apply(');
        $prepareAt = strpos($systemBody, 'prepare()->run(');
        $this->assertIsInt($composeAt);
        $this->assertIsInt($prepareAt);
        $this->assertLessThan($prepareAt, $composeAt);
    }

    private function applyMethodBody(string $class): string
    {
        $reflected = new \ReflectionMethod($class, 'apply');
        $file = (string) $reflected->getFileName();
        $lines = (array) file($file);
        $start = $reflected->getStartLine() - 1;

        return implode('', array_slice($lines, $start, $reflected->getEndLine() - $start));
    }

    /**
     * @param array<string, string> $files
     */
    private function makeFixtureDir(string $label, array $files): string
    {
        $dir = $this->tmpRoot . '/fixtures/' . $label;
        mkdir($dir, 0777, true);
        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return $dir;
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;

        return new class ($tmpRoot, $homeRoot) extends System {
            public function __construct(
                private string $engineRoot,
                private string $homeRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->homeRoot);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homeRoot . '/' . $username;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new class extends Process {
                    public function __construct()
                    {
                        parent::__construct(['true']);
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }

                    public function getOutput(): string
                    {
                        return '';
                    }
                };
            }

            public function filesystem(): \App\System\Filesystem
            {
                $system = $this;

                return new class ($system) extends \App\System\Filesystem {
                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }

                    public function copyFile(string $source, string $target, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->makeDirWithParents(dirname($target), $chown);
                        copy($source, $target);
                    }

                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $this->makeDirWithParents(dirname($path), $chown);
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
