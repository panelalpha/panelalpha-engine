<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\Paths;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Ticket 04: {@see DindCopyVolumesTest} never references the run-file name,
 * so nothing proved a volume-prepare pass targets the hardened run file
 * (ADR-0001) rather than the client's own compose file once one exists.
 *
 * {@see CopyVolumes::prepareVolumes()} builds its command through
 * {@see Paths::composeCommandForDirectory()} -- the same run-file-aware
 * chooser {@see \Tests\Unit\System\Project\Dind\PathsTest::test_compose_command_for_directory_uses_the_run_file_once_it_exists()}
 * already proves picks the run file over the client's -- so this closes the
 * one remaining question: that the call site actually reaches it, on a real
 * temp filesystem rather than a `sudo test -f` stub that cannot tell files
 * apart.
 */
class CopyVolumesRunFileTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    /** @var list<string> every command handed to System::exec() */
    private array $execLog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-copyvol-runfile-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->execLog = [];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_prepare_volumes_targets_the_run_file_once_one_exists(): void
    {
        $dind = $this->dindProject('gabe', Strategies::COMPOSE);
        $appDir = $this->appDir('gabe');
        file_put_contents($appDir . '/docker-compose.yml', "services:\n  app:\n    build: .\n");
        file_put_contents($appDir . '/' . EngineArtifacts::RUN_COMPOSE, "services:\n  app:\n    image: hardened\n");

        $paths = new Paths($dind);
        // ContainerOperations is final, so it cannot be stubbed; prepareVolumes()
        // never calls it, and a real one on the same fake System runs nothing.
        $containers = new ContainerOperations($dind, $dind->shell());
        (new CopyVolumes($dind, $containers, $paths))->prepareVolumes();

        $this->assertNotEmpty($this->execLog, 'prepareVolumes() never ran anything');
        $line = implode(' | ', $this->execLog);
        // The argv is shell-escaped into a `su -c '...'` string by the time it
        // reaches System::exec(), so this checks for the run file's distinct
        // name -- 'docker-compose.yml' is not a substring of
        // 'docker-compose.panelalpha.yml' -- rather than parsing `-f` flags
        // out of an escaped string.
        $this->assertStringContainsString(
            EngineArtifacts::RUN_COMPOSE,
            $line,
            'prepareVolumes() must target the run file once one exists'
        );
    }

    private function dindProject(string $username, string $strategy): Dind
    {
        $model = new ModelsUser();
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
        return $this->homeRoot . '/' . $username . '/project';
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;
        $execLog = &$this->execLog;

        return new class ($tmpRoot, $homeRoot, $execLog) extends System {
            /** @param list<string> $execLog */
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private array &$execLog,
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
                $exitCode = (count($parts) >= 4 && $parts[0] === 'sudo' && $parts[1] === 'test' && $parts[2] === '-f')
                    ? (is_file($parts[3]) ? 0 : 1)
                    : 0;

                // Symfony Process has no public exit-code setter, so this
                // fakes one by actually running a trivial real process.
                $process = new Process([PHP_BINARY, '-r', 'exit(' . $exitCode . ');']);
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->execLog[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                return '';
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
