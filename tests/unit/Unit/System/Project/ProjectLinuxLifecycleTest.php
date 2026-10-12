<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use PHPUnit\Framework\TestCase;

/**
 * Linux isolation owned by Project (formerly Account).
 */
class ProjectLinuxLifecycleTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-linux-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home', 0777, true);
        mkdir($this->tmpRoot . '/users', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_copy_home_dir_from_rsyncs_full_home_for_non_dind(): void
    {
        $system = $this->recordingSystem();
        $source = $this->project($system, 'src', 'default');
        $dest = $this->project($system, 'dest', 'default');

        mkdir($system->projectHomeDirPath('src') . '/www', 0777, true);
        file_put_contents($system->projectHomeDirPath('src') . '/www/index.html', 'ok');
        mkdir($system->projectHomeDirPath('dest'), 0777, true);

        $dest->copyHomeDirFrom($source);

        $this->assertContains('rsync-home-full', $system->journal);
        $this->assertFileExists($system->projectHomeDirPath('dest') . '/www/index.html');
    }

    public function test_copy_home_dir_from_excludes_docker_for_dind(): void
    {
        $system = $this->recordingSystem();
        $source = $this->project($system, 'src', 'dind');
        $dest = $this->project($system, 'dest', 'dind');

        mkdir($system->projectHomeDirPath('src'), 0777, true);
        mkdir($system->projectHomeDirPath('dest'), 0777, true);

        $dest->copyHomeDirFrom($source);

        $this->assertContains('rsync-home-dind', $system->journal);
    }

    public function test_copy_project_config_from_rsyncs_redis_and_php_fpm_pool_dirs(): void
    {
        $system = $this->recordingSystem();
        $source = $this->project($system, 'src', 'default');
        $dest = $this->project($system, 'dest', 'default');

        $srcProject = $system->projectDirPath('src');
        mkdir("{$srcProject}/redis/conf.d", 0777, true);
        file_put_contents("{$srcProject}/redis/conf.d/custom.conf", 'maxmemory 1mb');
        mkdir($system->projectDirPath('dest'), 0777, true);

        $dest->copyProjectConfigFrom($source);

        $this->assertContains('rsync-project-redis/conf.d', $system->journal);
        $this->assertContains('chown-www-data-project', $system->journal);
    }

    public function test_tear_down_linux_isolation_removes_user_home_and_project_dir_without_runtime_delete(): void
    {
        $system = $this->recordingSystem(uidExists: true);
        $project = $this->project($system, 'alice', 'default');

        mkdir($system->projectHomeDirPath('alice'), 0777, true);
        mkdir($system->projectDirPath('alice'), 0777, true);

        $project->tearDownLinuxIsolation();

        $this->assertContains('userdel', $system->journal);
        $this->assertContains('rm-home', $system->journal);
        $this->assertContains('rm-project', $system->journal);
        $this->assertNotContains('project-delete', $system->journal);
    }

    public function test_tear_down_linux_isolation_does_not_force_remove_dind_outer_container(): void
    {
        $system = $this->recordingSystem();
        $project = $this->project($system, 'dinduser', 'dind');

        $project->tearDownLinuxIsolation();

        $this->assertNotContains('docker-rm-dind', $system->journal);
    }

    public function test_project_clone_uses_project_for_home_and_config_copy(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5) . '/core/app/System/Projects.php');
        $this->assertStringNotContainsString('use App\System\Account', $source);
        $this->assertStringNotContainsString('new Account', $source);
        $this->assertStringContainsString('copyHomeDirFrom', $source);
        $this->assertStringContainsString('copyProjectConfigFrom', $source);
        $this->assertStringNotContainsString('copyHomeDirFrom($source->connect())', $source);
    }

    private function project(System $system, string $username, string $template): Project
    {
        return new Project($system, $this->userModel($username, $template));
    }

    private function userModel(string $username, string $template): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function getDomains(): array
            {
                return [];
            }
        };
        $model->username = $username;
        $model->setDetails([
            'template' => $template,
            'UID' => 1001,
            'GID' => 1001,
        ]);

        return $model;
    }

    private function recordingSystem(bool $uidExists = false): System
    {
        return new class ($this->tmpRoot, $uidExists) extends System {
            /** @var list<string> */
            public array $journal = [];

            public function __construct(
                private string $engineRoot,
                private bool $uidExistsFlag,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->engineRoot . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'rm -rf') && str_contains($line, '/home/')) {
                    $this->journal[] = 'rm-home';
                    $this->simulateRmRf($line);
                }
                if (str_contains($line, 'rm -rf') && str_contains($line, '/users/')) {
                    $this->journal[] = 'rm-project';
                    $this->simulateRmRf($line);
                }

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'userdel')) {
                    $this->journal[] = 'userdel';
                }

                return "1001\n";
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'rsync') && str_contains($line, '--exclude=docker/')) {
                    $this->journal[] = 'rsync-home-dind';
                    $this->simulateRsync($line);
                } elseif (str_contains($line, 'rsync') && str_contains($line, '/home/')) {
                    $this->journal[] = 'rsync-home-full';
                    $this->simulateRsync($line);
                } elseif (str_contains($line, 'rsync') && str_contains($line, 'redis/conf.d')) {
                    $this->journal[] = 'rsync-project-redis/conf.d';
                }
                if (str_contains($line, 'chown') && str_contains($line, 'www-data')) {
                    $this->journal[] = 'chown-www-data-project';
                }
                if (str_contains($line, 'docker rm -f')) {
                    $this->journal[] = 'docker-rm-dind';
                }

                return new class extends \Symfony\Component\Process\Process {
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

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                return $this->runProcess($cmd, $env);
            }

            public function isUidExists(string $username): bool
            {
                return $this->uidExistsFlag;
            }

            public function filesystem(): \App\System\Filesystem
            {
                return new class ($this) extends \App\System\Filesystem {
                    public function getHomeFilesystemMountPoint(): string
                    {
                        return '/home';
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }
                };
            }

            private function simulateRsync(string $line): void
            {
                if (!preg_match('/rsync -a --delete(?: --exclude=docker\/)? (.+) (.+)$/', $line, $m)) {
                    return;
                }
                $src = rtrim(trim($m[1]), '/');
                $dest = rtrim(trim($m[2]), '/');
                if (!is_dir($dest)) {
                    mkdir($dest, 0777, true);
                }
                if (is_dir($src)) {
                    $this->copyDir($src, $dest);
                }
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

            private function simulateRmRf(string $line): void
            {
                if (!preg_match('/rm -rf (.+)$/', $line, $m)) {
                    return;
                }
                $path = trim($m[1]);
                if (is_dir($path)) {
                    $this->removeTree($path);
                }
            }

            private function removeTree(string $dir): void
            {
                if (!is_dir($dir)) {
                    return;
                }
                foreach (scandir($dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    is_dir($path) ? $this->removeTree($path) : unlink($path);
                }
                rmdir($dir);
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
