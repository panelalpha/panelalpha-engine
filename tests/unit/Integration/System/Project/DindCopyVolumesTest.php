<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\CopyVolumes;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class DindCopyVolumesTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    /** @var list<string> */
    private array $processLog = [];

    /** @var list<string> */
    private array $rootLog = [];

    public bool $failComposeCreate = false;

    public bool $recordInnerCompose = false;

    /** @var list<array<string, mixed>>|null each container's inspect, as the account's daemon gives it; null when it does not answer */
    public ?array $innerInspect = null;

    public function logProcess(string $line): void
    {
        $this->processLog[] = $line;
    }

    public function logRootCommand(string $line): void
    {
        $this->rootLog[] = $line;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-vol-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->processLog = [];
        $this->rootLog = [];
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->tmpRoot . '/users/bob', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_project_aggregate_exposes_clone_volume_helpers(): void
    {
        $methods = get_class_methods(Project::class);
        $this->assertContains('copyVolumeDataFrom', $methods);
        $this->assertContains('copyVolumesForClone', $methods);
        $this->assertContains('startUserApp', $methods);
        $this->assertContains('syncGeneratedProxyRules', $methods);
    }

    public function test_application_exposes_copy_volumes_collaborator(): void
    {
        $project = $this->dindProject('alice');
        $app = $project->app();

        $this->assertInstanceOf(CopyVolumes::class, $app->copyVolumes());
        $this->assertSame($app->copyVolumes(), $app->copyVolumes());
    }

    public function test_an_anonymous_volume_holding_data_is_named_with_where_it_is_mounted(): void
    {
        $this->mkdirVolumeData('alice', 'project_db', 'seed');
        $this->mkdirVolumeData('alice', str_repeat('ab', 32), 'mysql data');
        $this->mkdirVolumeDir('alice', str_repeat('cd', 32));
        $this->innerInspect = [
            self::container('c1', 'db', [str_repeat('ab', 32) => '/var/lib/mysql', 'project_db' => '/data']),
            self::container('c2', 'web', [str_repeat('cd', 32) => '/var/www/html']),
        ];

        $warnings = $this->dindProject('alice')->app()->copyVolumes()->anonymousVolumeWarnings();

        $this->assertSame(
            [
                'Anonymous volume abababababab (db:/var/lib/mysql) holds data that was not copied. '
                . "Name it under the service's volumes: in the compose file to have it copied.",
            ],
            $warnings
        );
    }

    public function test_an_anonymous_volume_no_container_mounts_is_not_named(): void
    {
        $this->mkdirVolumeData('alice', str_repeat('ab', 32), 'left behind by a removed container');
        $this->innerInspect = [self::container('c1', 'db', ['project_db' => '/data'])];

        $this->assertSame([], $this->dindProject('alice')->app()->copyVolumes()->anonymousVolumeWarnings());
    }

    public function test_every_anonymous_volume_holding_data_is_named_when_the_containers_cannot_be_read(): void
    {
        $this->mkdirVolumeData('alice', str_repeat('ab', 32), 'mysql data');

        $warnings = $this->dindProject('alice')->app()->copyVolumes()->anonymousVolumeWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith('Anonymous volume abababababab holds data that was not copied.', $warnings[0]);
    }

    public function test_no_anonymous_volume_means_no_warning_and_no_container_lookup(): void
    {
        $this->mkdirVolumeData('alice', 'project_db', 'seed');

        $this->assertSame([], $this->dindProject('alice')->app()->copyVolumes()->anonymousVolumeWarnings());
        $this->assertSame([], array_values(array_filter($this->processLog, static fn (string $line): bool => str_starts_with($line, 'account-docker.'))));
    }

    public function test_prepare_volumes_failure_does_not_remove_project_compose(): void
    {
        $project = $this->dindProject('alice');
        $compose = $this->homeRoot . '/alice/project/docker-compose.yml';
        file_put_contents($compose, "services:\n  app:\n    image: test\n");

        $this->failComposeCreate = true;

        try {
            $project->app()->copyVolumes()->prepareVolumes();
            $this->fail('Expected RuntimeException from prepareVolumes');
        } catch (\RuntimeException) {
            $this->assertFileExists($compose);
        }
    }

    public function test_pause_for_copy_invokes_inner_compose_stop(): void
    {
        $project = $this->dindProject('alice');
        file_put_contents($this->tmpRoot . '/users/alice/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        file_put_contents($this->homeRoot . '/alice/project/docker-compose.yml', "services:\n  app:\n    image: test\n");

        $this->recordInnerCompose = true;

        $project->app()->copyVolumes()->pauseForCopy();

        $this->assertTrue(
            (bool) array_filter(
                $this->processLog,
                // execAsUser() quotes each argument, so match on the unquoted line.
                static fn (string $line): bool => str_contains(str_replace("'", '', $line), ' compose ')
                    && str_contains(str_replace("'", '', $line), ' stop')
            )
        );
    }

    public function test_a_copied_source_starts_again_without_rewriting_its_run_file(): void
    {
        $project = $this->dindProject('alice', Strategies::COMPOSE);
        $runFile = $this->homeRoot . '/alice/project/' . EngineArtifacts::RUN_COMPOSE;
        file_put_contents($runFile, "services:\n  app:\n    image: as-deployed\n");

        $project->app()->copyVolumes()->resumeSourceAfterCopy();

        $this->assertSame(['start'], $this->innerComposeActions());
        $this->assertSame([], array_values(array_filter(
            $this->rootLog,
            static fn (string $line): bool => str_starts_with($line, 'cp '),
        )));
        $this->assertSame("services:\n  app:\n    image: as-deployed\n", file_get_contents($runFile));
    }

    public function test_a_push_target_resumes_with_up(): void
    {
        $project = $this->dindProject('bob');

        $project->app()->copyVolumes()->resumeAfterCopy();

        $this->assertSame(['up -d --remove-orphans'], $this->innerComposeActions());
    }

    /** @return list<string> what each compose command run inside the account was asked to do */
    private function innerComposeActions(): array
    {
        $actions = [];
        foreach ($this->processLog as $line) {
            $unquoted = str_replace("'", '', $line);
            if (str_contains($unquoted, ' compose --project-directory ')) {
                $actions[] = substr($unquoted, strrpos($unquoted, '.yml ') + 5);
            }
        }

        return $actions;
    }

    /**
     * @param  array<string, string>  $volumes  volume name => mount point
     * @return array<string, mixed>
     */
    private static function container(string $id, string $service, array $volumes): array
    {
        $mounts = [['Type' => 'bind', 'Name' => '', 'Source' => '/home/alice/project', 'Destination' => '/app']];
        foreach ($volumes as $name => $destination) {
            $mounts[] = ['Type' => 'volume', 'Name' => $name, 'Source' => "/var/lib/docker/volumes/{$name}/_data", 'Destination' => $destination];
        }

        return ['Id' => $id, 'Config' => ['Labels' => ['com.docker.compose.service' => $service]], 'Mounts' => $mounts];
    }

    private function dindProject(string $username, string $strategy = 'express'): Dind
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => $strategy,
            'UID' => 1000,
            'GID' => 1000,
        ]);

        $projectDir = $this->tmpRoot . '/users/' . $username;
        is_dir($projectDir) || mkdir($projectDir, 0777, true);
        is_dir($this->homeRoot . '/' . $username . '/project') || mkdir($this->homeRoot . '/' . $username . '/project', 0777, true);
        file_put_contents(
            $projectDir . '/docker-compose.yml',
            "services:\n  dind:\n    image: test\n"
        );
        file_put_contents(
            $this->homeRoot . '/' . $username . '/project/docker-compose.yml',
            "services:\n  app:\n    image: test\n"
        );

        return $this->dindFromModel($model);
    }

    private function dindFromModel(ModelsUser $model): Dind
    {
        $runtime = (new Project($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;
        $test = $this;

        return new class ($tmpRoot, $homeRoot, $test) extends System {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private DindCopyVolumesTest $test,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->homesRoot);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homesRoot . '/' . $username;
            }

            public function projectDirPath(string $username): string
            {
                return $this->engineRoot . '/users/' . $username;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $parts = is_array($cmd) ? $cmd : (preg_split('/\s+/', $cmd) ?: []);
                $line = implode(' ', $parts);
                $this->test->logProcess($line);

                if ($this->test->recordInnerCompose
                    && in_array('compose', $parts, true)
                    && in_array('stop', $parts, true)) {
                    return FakeProcess::ok();
                }

                $action = LocalRoot::action($parts);
                $values = $action === null ? [] : LocalRoot::values($parts);
                if ($action !== null) {
                    $this->test->logRootCommand(implode(' ', LocalRoot::plain($parts)));
                }

                if ($action === 'files.isDirectory') {
                    return is_dir($values[0]) ? FakeProcess::ok() : FakeProcess::failed();
                }

                if ($action === 'files.listNames') {
                    $dir = $values[0];
                    $entries = [];
                    if (is_dir($dir)) {
                        foreach (scandir($dir) ?: [] as $entry) {
                            if ($entry === '.' || $entry === '..') {
                                continue;
                            }
                            $entries[] = $entry;
                        }
                    }
                    return FakeProcess::ok(implode("\n", $entries));
                }

                if ($action === 'files.firstEntry') {
                    $entries = is_dir($values[0]) ? array_diff(scandir($values[0]) ?: [], ['.', '..']) : [];

                    return FakeProcess::ok($entries === [] ? '' : $values[0] . '/' . reset($entries) . "\n");
                }

                if ($action === 'files.makeDirectories') {
                    foreach ($values as $target) {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }
                }

                return FakeProcess::ok();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if ($this->test->failComposeCreate) {
                    throw new \RuntimeException('compose create failed');
                }

                $parts = is_array($cmd) ? $cmd : (preg_split('/\s+/', (string) $cmd) ?: []);
                $action = LocalRoot::action($parts);
                if ($action !== null && str_starts_with($action, 'account-docker.')) {
                    $this->test->logProcess($action);
                    $inspected = $this->test->innerInspect ?? throw new \RuntimeException('inner daemon unreachable');

                    return (string) json_encode($action === 'account-docker.listContainers'
                        ? array_map(static fn (array $container): array => ['Id' => $container['Id']], $inspected)
                        : $inspected);
                }
                $this->runProcess($parts, $env, $timeout);

                return '';
            }
        };
    }

    private function mkdirVolumeDir(string $username, string $volumeName): void
    {
        mkdir($this->volumeDataPath($username, $volumeName), 0777, true);
    }

    private function mkdirVolumeData(string $username, string $volumeName, string $markerValue): void
    {
        $this->mkdirVolumeDir($username, $volumeName);
        file_put_contents($this->volumeDataPath($username, $volumeName) . '/marker.txt', $markerValue);
    }

    private function volumeDataPath(string $username, string $volumeName): string
    {
        return $this->homeRoot . '/' . $username . '/docker/volumes/' . $volumeName . '/_data';
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
