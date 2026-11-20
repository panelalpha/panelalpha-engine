<?php

namespace Tests\Support;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\Inner\SharedBaseImages;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;

trait HostCompileFrontendBuildFixtures
{
    /** @param array<string, string> $files absolute account path => contents */
    private function system(array $files, int $exit = 0, string $stderr = ''): System
    {
        return new class($files, $exit, $stderr) extends System
        {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @param array<string, string> $files */
            public function __construct(private array $files, private int $exit = 0, private string $stderr = '') {}

            public function filesystem(): SystemFilesystem
            {
                return new class($this, $this->files) extends SystemFilesystem
                {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                if (is_array($cmd) && LocalRoot::build($cmd) !== []) {
                    $this->commands[] = $cmd;
                }
                $process = new Process([
                    'php', '-r', 'echo "Running webpack ..."; fwrite(STDERR, '.var_export($this->stderr, true).'); exit('.$this->exit.');',
                ]);
                $process->run();

                return $process;
            }
        };
    }

    private function project(System $system, ?SharedBaseImages $bases = null): Dind
    {
        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $engine = $this->createStub(ContainerEngine::class);
        $engine->method('hostBuilder')->willReturn(new DindHostBuilder);

        $dind = $this->createStub(Dind::class);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('engine')->willReturn($engine);
        $dind->method('engineAccount')->willReturn(new EngineAccount('acme', '/home/acme', '1001:1001'));
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        if ($bases !== null) {
            $inner = $this->createStub(InnerDocker::class);
            $inner->method('bases')->willReturn($bases);
            $dind->method('innerDocker')->willReturn($inner);
        }

        return $dind;
    }

    /**
     * @param  array<string, string>  $files
     * @return array{0: bool, 1: list<list<string>>} whether it ran, and the containers
     */
    private function runForPhp(
        array $files,
        string|false|null $frontendBuild,
        ?string $phpImage = null,
        ?SharedBaseImages $bases = null,
        int $exit = 0,
        string $stderr = ''
    ): array {
        $system = $this->system($files + [
            '/home/acme/project/composer.json' => '{"require":{"php":"^8.2"}}',
            '/home/acme/project/vendor/autoload.php' => '<?php',
        ], $exit, $stderr);
        // The Node image choice scans the real disk, where /home/acme is not.
        set_error_handler(static fn (int $no, string $message): bool => str_starts_with($message, 'scandir('), E_WARNING);
        try {
            $ran = (new HostCompile($this->project($system, $bases)))
                ->runForPhp('/home/acme/project', [], '', $frontendBuild, $phpImage);
        } finally {
            restore_error_handler();
        }

        return [$ran, $system->commands];
    }
}
