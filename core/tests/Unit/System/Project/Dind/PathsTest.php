<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PathsTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/pa-paths-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    /**
     * Every other reader of "which filenames count as the inner compose
     * file" goes through this list, in docker compose's own search order.
     */
    public function test_compose_file_candidates_are_the_docker_compose_v2_search_order(): void
    {
        $this->assertSame(
            ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'],
            Paths::composeFileCandidates()
        );
    }

    public function test_a_file_carrying_the_generated_label_is_the_engines_own(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'compose');
        file_put_contents($path, '# '.GeneratedCompose::LABEL."\nservices:\n  app:\n    image: nginx:alpine\n");

        try {
            $this->assertTrue(Paths::isEngineComposeFile($path));
        } finally {
            unlink($path);
        }
    }

    public function test_a_repository_shipped_compose_file_is_not_the_engines_own(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'compose');
        file_put_contents($path, "services:\n  app:\n    build: .\n  db:\n    image: postgres:16\n");

        try {
            $this->assertFalse(Paths::isEngineComposeFile($path));
        } finally {
            unlink($path);
        }
    }

    public function test_a_missing_file_is_not_the_engines_own(): void
    {
        $this->assertFalse(Paths::isEngineComposeFile('/nonexistent/'.uniqid('', true).'/docker-compose.yml'));
    }

    /**
     * A reserved run-file name is the engine's own by name alone — no content
     * check needed, and none possible for the compose-strategy run file,
     * which is the client's own hardened content.
     */
    public function test_a_reserved_run_file_name_is_the_engines_own_even_with_client_content(): void
    {
        $dir = $this->tmpRoot.'/reserved';
        mkdir($dir, 0777, true);
        $path = $dir.'/'.EngineArtifacts::RUN_COMPOSE;
        file_put_contents($path, "services:\n  app:\n    build: .\n");

        $this->assertTrue(Paths::isEngineComposeFile($path));
    }

    public function test_compose_files_layers_the_client_override_only_for_compose_and_paemd(): void
    {
        $dind = $this->dindProject('alice', Strategies::COMPOSE);
        $this->writeRunFile('alice');
        file_put_contents($this->appDir('alice').'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");

        $this->assertContains(
            $this->appDir('alice').'/'.Paths::CLIENT_OVERRIDE_FILENAME,
            $this->composeFileArgs($dind)
        );
    }

    public function test_compose_files_never_layers_the_client_override_for_a_recipe_strategy(): void
    {
        $dind = $this->dindProject('bob', 'express');
        $this->writeRunFile('bob');
        file_put_contents($this->appDir('bob').'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");

        $this->assertNotContains(
            $this->appDir('bob').'/'.Paths::CLIENT_OVERRIDE_FILENAME,
            $this->composeFileArgs($dind)
        );
    }

    public function test_compose_files_always_layers_the_engine_override_when_present(): void
    {
        $dind = $this->dindProject('carol', 'express');
        $this->writeRunFile('carol');
        file_put_contents($this->appDir('carol').'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE, "services: {}\n");

        $this->assertContains(
            $this->appDir('carol').'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            $this->composeFileArgs($dind)
        );
    }

    /**
     * D8 says "compose or PAEMD" — COMPOSE alone is not the whole rule.
     */
    public function test_compose_files_layers_the_client_override_for_paemd_too(): void
    {
        $dind = $this->dindProject('faye', Strategies::PAEMD);
        $this->writeRunFile('faye');
        file_put_contents($this->appDir('faye').'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");

        $this->assertContains(
            $this->appDir('faye').'/'.Paths::CLIENT_OVERRIDE_FILENAME,
            $this->composeFileArgs($dind)
        );
    }

    /**
     * composeCommandForDirectory() (used by CopyVolumes for a staging clone,
     * a directory that is not the account's own appDir()) layers the same
     * way as composeFiles() (D8) — the run file (or the project's own, before
     * one exists) first, then the client override for compose/PAEMD, then the
     * engine override whenever present.
     */
    public function test_compose_command_for_directory_uses_the_run_file_once_it_exists(): void
    {
        $dind = $this->dindProject('gabe', Strategies::COMPOSE);
        $dir = $this->tmpRoot.'/staging';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/docker-compose.yml', "services:\n  app:\n    build: .\n");
        file_put_contents($dir.'/'.EngineArtifacts::RUN_COMPOSE, "services:\n  app:\n    image: hardened\n");

        $this->assertSame(
            [$dir.'/'.EngineArtifacts::RUN_COMPOSE],
            $this->composeDirFileArgs($dind, $dir)
        );
    }

    public function test_compose_command_for_directory_falls_back_to_the_existing_compose_file_before_a_run_file_exists(): void
    {
        $dind = $this->dindProject('hank', Strategies::COMPOSE);
        $dir = $this->tmpRoot.'/staging';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/docker-compose.yml', "services:\n  app:\n    build: .\n");

        $this->assertSame(
            [$dir.'/docker-compose.yml'],
            $this->composeDirFileArgs($dind, $dir)
        );
    }

    public function test_compose_command_for_directory_layers_the_client_override_only_for_compose_and_paemd(): void
    {
        $dind = $this->dindProject('iris', Strategies::COMPOSE);
        $dir = $this->tmpRoot.'/staging';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.EngineArtifacts::RUN_COMPOSE, "services: {}\n");
        file_put_contents($dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");

        $this->assertContains(
            $dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME,
            $this->composeDirFileArgs($dind, $dir)
        );
    }

    public function test_compose_command_for_directory_never_layers_the_client_override_for_a_recipe_strategy(): void
    {
        $dind = $this->dindProject('jack', 'express');
        $dir = $this->tmpRoot.'/staging';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.EngineArtifacts::RUN_COMPOSE, "services: {}\n");
        file_put_contents($dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");

        $this->assertNotContains(
            $dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME,
            $this->composeDirFileArgs($dind, $dir)
        );
    }

    public function test_compose_command_for_directory_always_layers_the_engine_override_after_the_client_one(): void
    {
        $dind = $this->dindProject('kara', Strategies::COMPOSE);
        $dir = $this->tmpRoot.'/staging';
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.EngineArtifacts::RUN_COMPOSE, "services: {}\n");
        file_put_contents($dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services: {}\n");
        file_put_contents($dir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE, "services: {}\n");

        $this->assertSame(
            [
                $dir.'/'.EngineArtifacts::RUN_COMPOSE,
                $dir.'/'.Paths::CLIENT_OVERRIDE_FILENAME,
                $dir.'/'.EngineArtifacts::RUN_COMPOSE_OVERRIDE,
            ],
            $this->composeDirFileArgs($dind, $dir)
        );
    }

    public function test_compose_file_for_ports_prefers_the_run_file_once_it_exists(): void
    {
        $dind = $this->dindProject('dana', Strategies::COMPOSE);
        file_put_contents($this->appDir('dana').'/docker-compose.yml', "services:\n  app:\n    ports: ['8080:80']\n");
        $this->writeRunFile('dana', "services:\n  app:\n    ports: ['9090:80']\n");

        $this->assertSame(
            $this->appDir('dana').'/'.EngineArtifacts::RUN_COMPOSE,
            $dind->userAppComposeFileForPorts()
        );
    }

    public function test_compose_file_for_ports_falls_back_to_the_projects_own_file_before_a_run_file_exists(): void
    {
        $dind = $this->dindProject('eve', Strategies::COMPOSE);
        file_put_contents($this->appDir('eve').'/docker-compose.yml', "services:\n  app:\n    ports: ['8080:80']\n");

        $this->assertSame(
            $this->appDir('eve').'/docker-compose.yml',
            $dind->userAppComposeFileForPorts()
        );
    }

    /**
     * @return list<string>
     */
    private function composeFileArgs(Dind $dind): array
    {
        return $this->fileArgsFromCommand($dind->userAppComposeCommand([]));
    }

    /**
     * @return list<string>
     */
    private function composeDirFileArgs(Dind $dind, string $dir): array
    {
        return $this->fileArgsFromCommand((new Paths($dind))->composeCommandForDirectory($dir));
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function fileArgsFromCommand(array $command): array
    {
        $files = [];
        foreach ($command as $i => $arg) {
            if ($arg === '-f' && isset($command[$i + 1])) {
                $files[] = $command[$i + 1];
            }
        }

        return $files;
    }

    private function dindProject(string $username, string $strategy): Dind
    {
        $model = new ModelsUser;
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => $strategy,
            'UID' => 1000,
            'GID' => 1000,
        ]);

        mkdir($this->appDir($username), 0777, true);

        $runtime = (new Project($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function appDir(string $username): string
    {
        return $this->homeRoot.'/'.$username.'/project';
    }

    private function writeRunFile(string $username, string $contents = "services:\n  app:\n    image: test\n"): void
    {
        file_put_contents($this->appDir($username).'/'.EngineArtifacts::RUN_COMPOSE, $contents);
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;

        return new class($tmpRoot, $homeRoot) extends System
        {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {}

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
                return $this->homesRoot.'/'.$username;
            }

            public function projectDirPath(string $username): string
            {
                return $this->engineRoot.'/users/'.$username;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $parts = is_array($cmd) ? $cmd : (preg_split('/\s+/', $cmd) ?: []);
                $exitCode = (count($parts) >= 4 && $parts[0] === 'sudo' && $parts[1] === 'test' && $parts[2] === '-f')
                    ? (is_file($parts[3]) ? 0 : 1)
                    : 0;

                // Symfony Process has no public exit-code setter, so this
                // fakes one by actually running a trivial real process.
                $process = new Process([PHP_BINARY, '-r', 'exit('.$exitCode.');']);
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            is_dir($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
