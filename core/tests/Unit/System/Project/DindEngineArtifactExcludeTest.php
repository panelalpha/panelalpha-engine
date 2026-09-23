<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\EngineArtifactExclude;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Engine Artifacts are listed in the checkout's local exclude file, against a
 * real git repository: `git status` stays clean and a forced pull keeps them.
 */
class DindEngineArtifactExcludeTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/pa-exclude-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->checkout = $this->homeRoot . '/alice/project';
        mkdir($this->checkout, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_engine_artifacts_leave_git_status_clean_and_survive_a_forced_pull(): void
    {
        $this->gitInit();
        $this->put('composer.json', "{}\n");
        $this->put('.env.example', "APP_KEY=\n");
        $this->commitAll();

        $this->put('panelalpha.Dockerfile', "FROM php\n");
        $this->put('panelalpha-entrypoint.sh', "#!/bin/sh\n");
        $this->put('.env.default', "APP_KEY=\n");
        $this->put('.env', "APP_KEY=base64:kept\n");
        $this->put('.dockerignore', "node_modules\n");

        $this->exclude()->write();

        $this->assertSame('', $this->git('status', '--porcelain'));

        $this->git('reset', '--hard', 'HEAD');
        $this->git('clean', '-fd');

        $this->assertSame("APP_KEY=base64:kept\n", file_get_contents($this->checkout . '/.env'));
        $this->assertFileExists($this->checkout . '/panelalpha.Dockerfile');
    }

    public function test_a_generated_file_the_repository_tracks_is_not_listed(): void
    {
        $this->gitInit();
        $this->put('.env', "APP_KEY=committed\n");
        $this->put('.dockerignore', "vendor\n");
        $this->commitAll();

        $this->exclude()->write();

        $lines = explode("\n", $this->excludeFile('.git/info/exclude'));
        $this->assertNotContains('/.env', $lines);
        $this->assertNotContains('/.dockerignore', $lines);
        $this->assertContains('/panelalpha.Dockerfile', $lines);
    }

    public function test_client_lines_are_kept_and_a_second_write_changes_nothing(): void
    {
        $this->gitInit();
        file_put_contents($this->checkout . '/.git/info/exclude', "*.swp\n");
        $this->put('.env', "A=1\n");

        $this->exclude()->write();
        $first = $this->excludeFile('.git/info/exclude');
        $this->exclude()->write();

        $this->assertStringStartsWith("*.swp\n", $first);
        $this->assertSame($first, $this->excludeFile('.git/info/exclude'));
    }

    public function test_nested_env_copies_placeholder_page_and_env_file_targets_are_listed(): void
    {
        $this->gitInit();
        $this->put('api/.env.example', "X=1\n");
        $this->put('docker-compose.yml', "services:\n  app:\n    image: nginx\n    env_file:\n      - config/app.env\n");
        $this->put('.panelalpha/app.json', "{}\n");
        $this->commitAll();

        $this->put('api/.env', "X=1\n");
        $this->put('config/app.env', '');
        $this->put('index.html', '<title>PanelAlpha — Ready</title>');
        $this->put('.panelalpha/entrypoint.sh', "#!/bin/sh\n");

        $this->exclude()->write();

        $lines = explode("\n", $this->excludeFile('.git/info/exclude'));
        $this->assertContains('/api/.env', $lines);
        $this->assertContains('/config/app.env', $lines);
        $this->assertContains('/index.html', $lines);
        $this->assertContains('/.panelalpha/entrypoint.sh', $lines);
        $this->assertSame('', $this->git('status', '--porcelain'));
    }

    public function test_a_real_index_page_is_not_listed(): void
    {
        $this->gitInit();
        $this->put('index.html', '<title>My shop</title>');

        $this->exclude()->write();

        $this->assertNotContains('/index.html', explode("\n", $this->excludeFile('.git/info/exclude')));
    }

    public function test_checkout_whose_git_directory_is_a_gitfile_gets_the_block_in_that_directory(): void
    {
        $gitDir = $this->tmpRoot . '/separate-git-dir';
        $this->shell(['git', 'init', '-q', '--separate-git-dir=' . $gitDir, $this->checkout]);
        $this->put('.env', "A=1\n");

        $this->exclude()->write();

        $this->assertFileExists($this->checkout . '/.git');
        $this->assertStringContainsString("\n/.env\n", file_get_contents($gitDir . '/info/exclude'));
        $this->assertSame('', $this->git('status', '--porcelain'));
    }

    public function test_checkout_without_git_is_left_alone(): void
    {
        $this->put('.env', "A=1\n");

        $this->exclude()->write();

        $this->assertDirectoryDoesNotExist($this->checkout . '/.git');
    }

    public function test_checkout_inside_another_repository_does_not_touch_that_repository(): void
    {
        $this->shell(['git', 'init', '-q', $this->homeRoot]);
        file_put_contents($this->homeRoot . '/.git/info/exclude', "*.swp\n");
        $this->put('.env', "A=1\n");

        $this->exclude()->write();

        $this->assertSame("*.swp\n", file_get_contents($this->homeRoot . '/.git/info/exclude'));
    }

    private function exclude(): EngineArtifactExclude
    {
        $git = new TestableGitRepository($this->dind(), function (array $command): string {
            return $this->shell($command);
        });

        return new EngineArtifactExclude($git, static function (string $path, string $contents): void {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        });
    }

    private function gitInit(): void
    {
        $this->shell(['git', 'init', '-q', $this->checkout]);
    }

    private function commitAll(): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'init');
    }

    private function git(string ...$args): string
    {
        return $this->shell(['git', '-C', $this->checkout, ...$args]);
    }

    /**
     * @param list<string> $command
     */
    private function shell(array $command): string
    {
        $process = new Process($command);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput());
        }

        return $process->getOutput();
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->checkout . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function excludeFile(string $relative): string
    {
        return (string) file_get_contents($this->checkout . '/' . $relative);
    }

    private function dind(): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
            'git_repo' => 'https://github.com/org/repo.git',
        ]);

        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        return new class ($this->tmpRoot, $this->homeRoot) extends System {
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
            // git marks its objects read-only, which Windows refuses to unlink.
            @chmod($item->getPathname(), 0777);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
