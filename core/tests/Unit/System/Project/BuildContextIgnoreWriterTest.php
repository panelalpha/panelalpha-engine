<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Strategy\BuildContextIgnoreWriter;
use PHPUnit\Framework\TestCase;

/**
 * The engine writes `<Dockerfile>.dockerignore` into the account's checkout,
 * never touching a file the project owns. #208 / #197.
 */
class BuildContextIgnoreWriterTest extends TestCase
{
    private string $tmpRoot;
    private string $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-ctx-ignore-' . bin2hex(random_bytes(4));
        $this->project = $this->tmpRoot . '/home/alice/project';
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_repos_own_dockerfile_gets_git_and_the_engine_files_left_out(): void
    {
        $this->put('Dockerfile', "FROM golang:1-alpine\nCOPY . .\nRUN go build -o app\n");
        $this->put('.dockerignore', "node_modules\n.output\n");

        $this->writer()->write($this->project, 'Dockerfile', false, null);

        $written = $this->read('Dockerfile.dockerignore');
        $this->assertStringContainsString("node_modules\n.output\n", $written);
        $this->assertMatchesRegularExpression('/^\.git$/m', $written);
        $this->assertMatchesRegularExpression('/^docker-compose\.panelalpha\.yml$/m', $written);
        $this->assertSame("node_modules\n.output\n", $this->read('.dockerignore'), 'the project\'s file is not edited');
    }

    public function test_a_second_deploy_writes_the_same_bytes(): void
    {
        $this->put('Dockerfile', "FROM nginx\nCOPY . /usr/share/nginx/html\n");

        $this->writer()->write($this->project, 'Dockerfile', false, null);
        $first = $this->read('Dockerfile.dockerignore');
        $this->writer()->write($this->project, 'Dockerfile', false, null);

        $this->assertSame($first, $this->read('Dockerfile.dockerignore'));
    }

    public function test_a_dockerfile_that_reads_history_keeps_git(): void
    {
        $this->put('Dockerfile', "FROM golang:1.22\nRUN go build -ldflags \"-X main.v=$(git describe)\"\n");

        $this->writer()->write($this->project, 'Dockerfile', false, null);

        $this->assertDoesNotMatchRegularExpression('/^\.git$/m', $this->read('Dockerfile.dockerignore'));
        $this->assertMatchesRegularExpression('/^docker-compose\.panelalpha\.yml$/m', $this->read('Dockerfile.dockerignore'));
    }

    public function test_a_projects_own_dockerfile_ignore_is_left_alone(): void
    {
        $this->put('Dockerfile', "FROM nginx\n");
        $this->put('Dockerfile.dockerignore', "secrets\n");

        $this->writer()->write($this->project, 'Dockerfile', false, null);

        $this->assertSame("secrets\n", $this->read('Dockerfile.dockerignore'));
    }

    public function test_a_dockerfile_in_a_subdirectory_gets_its_file_beside_it(): void
    {
        mkdir($this->project . '/docker');
        $this->put('docker/Dockerfile', "FROM nginx\nCOPY . /srv\n");

        $this->writer()->write($this->project, 'docker/Dockerfile', false, null);

        $this->assertTrue(BuildContextIgnore::isEngineWritten($this->read('docker/Dockerfile.dockerignore')));
        $this->assertFileDoesNotExist($this->project . '/Dockerfile.dockerignore');
    }

    public function test_a_generated_build_no_longer_writes_a_dockerignore_into_the_checkout(): void
    {
        $this->put('panelalpha.Dockerfile', "FROM node:22\nCOPY . .\n");

        $this->writer()->write($this->project, 'panelalpha.Dockerfile', true, null);

        $this->assertFileDoesNotExist($this->project . '/.dockerignore');
        $written = $this->read('panelalpha.Dockerfile.dockerignore');
        $this->assertMatchesRegularExpression('/^node_modules$/m', $written);
        $this->assertMatchesRegularExpression('/^\.git$/m', $written);
    }

    private function writer(): BuildContextIgnoreWriter
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        // Through the accessor the strategies use.
        return $runtime->strategy()->contextIgnore();
    }

    private function put(string $name, string $contents): void
    {
        file_put_contents($this->project . '/' . $name, $contents);
    }

    private function read(string $name): string
    {
        return (string) file_get_contents($this->project . '/' . $name);
    }

    /** A System whose filesystem is plain local files, with no sudo. */
    private function system(): System
    {
        return new class ($this->tmpRoot) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        file_put_contents($path, $contents);
                    }
                };
            }
        };
    }
}
