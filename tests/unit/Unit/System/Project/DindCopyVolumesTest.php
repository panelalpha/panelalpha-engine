<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\CopyVolumes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

class DindCopyVolumesTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    /** @var list<string> */
    private array $processLog = [];

    public bool $failComposeCreate = false;

    public bool $recordInnerCompose = false;

    public function logProcess(string $line): void
    {
        $this->processLog[] = $line;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-vol-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->processLog = [];
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

    public function test_count_volume_data_dirs_returns_zero_when_volumes_tree_missing(): void
    {
        $project = $this->dindProject('alice');

        $this->assertSame(0, $project->app()->copyVolumes()->countVolumeDataDirs());
    }

    public function test_count_volume_data_dirs_counts_data_directories(): void
    {
        $this->mkdirVolumeData('alice', 'project_db', 'seed');

        $project = $this->dindProject('alice');

        $this->assertSame(1, $project->app()->copyVolumes()->countVolumeDataDirs());
    }

    public function test_copy_volume_data_from_returns_zero_when_dest_or_source_tree_missing(): void
    {
        $source = $this->dindProject('alice');
        $dest = $this->dindProject('bob');

        $this->assertSame(0, $dest->app()->copyVolumes()->copyVolumeDataFrom($source->homeDirPath()));
    }

    public function test_copy_volume_data_from_rsyncs_matching_volumes(): void
    {
        $this->mkdirVolumeData('alice', 'project_db', 'from-source');
        $this->mkdirVolumeDir('bob', 'project_db');

        $source = $this->dindProject('alice');
        $dest = $this->dindProject('bob');

        $copied = $dest->app()->copyVolumes()->copyVolumeDataFrom($source->homeDirPath());

        $this->assertSame(1, $copied);
        $this->assertSame(
            'from-source',
            file_get_contents($this->volumeDataPath('bob', 'project_db') . '/marker.txt')
        );
        $this->assertContains('rsync-volume', $this->processLog);
    }

    public function test_copy_volume_data_to_incoming_stages_under_data_incoming(): void
    {
        $this->mkdirVolumeData('alice', 'project_db', 'push-payload');
        $this->mkdirVolumeDir('bob', 'project_db');

        $source = $this->dindProject('alice');
        $dest = $this->dindProject('bob');

        $copied = $dest->app()->copyVolumes()->copyVolumeDataToIncoming($source->homeDirPath());

        $this->assertSame(1, $copied);
        $incoming = $this->homeRoot . '/bob/docker/volumes/project_db/_data.incoming/marker.txt';
        $this->assertFileExists($incoming);
        $this->assertSame('push-payload', file_get_contents($incoming));
    }

    public function test_swap_incoming_volumes_promotes_incoming_and_preserves_backup(): void
    {
        $this->mkdirVolumeData('bob', 'project_db', 'old');
        $incoming = $this->homeRoot . '/bob/docker/volumes/project_db/_data.incoming';
        mkdir($incoming, 0777, true);
        file_put_contents($incoming . '/marker.txt', 'new');

        $dest = $this->dindProject('bob');
        $dest->app()->copyVolumes()->swapIncomingVolumes();

        $this->assertSame('new', file_get_contents($this->volumeDataPath('bob', 'project_db') . '/marker.txt'));
        $backup = $this->homeRoot . '/bob/docker/volumes/project_db/_data.pre-push/marker.txt';
        $this->assertFileExists($backup);
        $this->assertSame('old', file_get_contents($backup));
    }

    public function test_discard_volume_backups_removes_staging_suffixes(): void
    {
        $this->mkdirVolumeData('bob', 'project_db', 'live');
        $base = $this->homeRoot . '/bob/docker/volumes/project_db';
        mkdir($base . '/_data.pre-push', 0777, true);
        mkdir($base . '/_data.incoming', 0777, true);

        $dest = $this->dindProject('bob');
        $dest->app()->copyVolumes()->discardVolumeBackups();

        $this->assertDirectoryDoesNotExist($base . '/_data.pre-push');
        $this->assertDirectoryDoesNotExist($base . '/_data.incoming');
        $this->assertDirectoryExists($base . '/_data');
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

    private function dindProject(string $username): Dind
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => 'express',
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

                if (count($parts) >= 4 && $parts[0] === 'sudo' && $parts[1] === 'test' && $parts[2] === '-d') {
                    $path = $parts[3];
                    return is_dir($path) ? FakeProcess::ok() : FakeProcess::failed();
                }

                if (($parts[0] ?? '') === 'sudo' && ($parts[1] ?? '') === 'ls' && ($parts[2] ?? '') === '-1') {
                    $dir = $parts[3] ?? '';
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

                if (($parts[0] ?? '') === 'sudo' && ($parts[1] ?? '') === 'mkdir') {
                    $target = end($parts) ?: '';
                    if (!is_dir($target)) {
                        mkdir($target, 0777, true);
                    }
                }

                if (($parts[0] ?? '') === 'sudo' && ($parts[1] ?? '') === 'rsync') {
                    $this->test->logProcess('rsync-volume');
                    $this->simulateRsync($parts);
                }

                if (($parts[0] ?? '') === 'sudo' && ($parts[1] ?? '') === 'mv') {
                    $from = $parts[2] ?? '';
                    $to = $parts[3] ?? '';
                    if (is_dir($from)) {
                        if (!is_dir(dirname($to))) {
                            mkdir(dirname($to), 0777, true);
                        }
                        rename($from, $to);
                    }
                }

                if (($parts[0] ?? '') === 'sudo' && ($parts[1] ?? '') === 'rm') {
                    $target = end($parts) ?: '';
                    $this->removeTree($target);
                }

                return FakeProcess::ok();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if ($this->test->failComposeCreate) {
                    throw new \RuntimeException('compose create failed');
                }

                $parts = is_array($cmd) ? $cmd : (preg_split('/\s+/', (string) $cmd) ?: []);
                $this->runProcess($parts, $env, $timeout);

                return '';
            }

            /**
             * @param list<string> $parts
             */
            private function simulateRsync(array $parts): void
            {
                $src = '';
                $dest = '';
                foreach ($parts as $i => $part) {
                    if ($part === '--delete') {
                        continue;
                    }
                    if (str_ends_with($part, '/')) {
                        if ($src === '') {
                            $src = rtrim($part, '/');
                        } else {
                            $dest = rtrim($part, '/');
                        }
                    }
                }
                if ($src === '' || $dest === '') {
                    return;
                }
                if (!is_dir($dest)) {
                    mkdir($dest, 0777, true);
                }
                $this->copyDir($src, $dest);
            }

            private function copyDir(string $src, string $dest): void
            {
                foreach (scandir($src) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $from = $src . '/' . $entry;
                    $to = $dest . '/' . $entry;
                    if (is_dir($from)) {
                        if (!is_dir($to)) {
                            mkdir($to, 0777, true);
                        }
                        $this->copyDir($from, $to);
                    } else {
                        copy($from, $to);
                    }
                }
            }

            private function removeTree(string $path): void
            {
                if (!file_exists($path)) {
                    return;
                }
                if (is_dir($path)) {
                    foreach (scandir($path) ?: [] as $entry) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }
                        $this->removeTree($path . '/' . $entry);
                    }
                    rmdir($path);
                } else {
                    unlink($path);
                }
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
