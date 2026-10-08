<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use PHPUnit\Framework\TestCase;

class ProjectExistsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-exists-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_exists_is_true_only_when_outer_compose_file_is_present(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $project = $system->project($this->userModel('alice', dind: true));

        $this->assertFalse($project->exists());

        file_put_contents($project->composeFilePath(), "services: {}\n");
        $this->assertTrue($project->exists());
    }

    public function test_exists_is_compose_only_not_host_occupancy(): void
    {
        $home = $this->tmpRoot . '/home';
        mkdir($home . '/alice', 0777, true);

        $system = new class ($this->tmpRoot, $home) extends System {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->homesRoot;
            }

            public function isUidExists(string $username): bool
            {
                return true;
            }
        };

        $project = $system->project($this->userModel('alice'));

        $this->assertTrue(is_dir($system->projectHomeDirPath('alice')));
        $this->assertTrue($system->isUidExists('alice'));
        $this->assertFalse($project->exists());

        $projectDir = $system->projectDirPath('alice');
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0777, true);
        }
        file_put_contents($project->composeFilePath(), "services: {}\n");
        $this->assertTrue($project->exists());
    }

    public function test_compose_file_path_uses_project_dir(): void
    {
        $system = $this->systemWithEngineRoot($this->tmpRoot);
        $project = $system->project($this->userModel('alice', dind: true));

        $this->assertSame(
            $this->tmpRoot . '/users/alice/docker-compose.yml',
            $project->composeFilePath()
        );
    }

    private function userModel(string $username, bool $dind = false): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        if ($dind) {
            $model->setDetails(['template' => 'dind']);
        }

        return $model;
    }

    private function systemWithEngineRoot(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
