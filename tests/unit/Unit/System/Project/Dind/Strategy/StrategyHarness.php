<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\ComposeWriter;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Unit\System\Project\SuccessfulProcess;

/**
 * A Dind over a real temporary checkout: reads come from disk, while every write, command, compose
 * file and collaborator call is recorded in order with the deploy log, and compared with a golden file.
 */
trait StrategyHarness
{
    private string $dir = '';

    private string $username = '';

    private ?DeployLogger $logger = null;

    /** @var list<string> */
    private array $events = [];

    private function setUpHarness(): void
    {
        $this->dir = sys_get_temp_dir().'/pa-strategy-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        $this->events = [];
        $this->username = 'chr'.bin2hex(random_bytes(4));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->logger = DeployLogger::start($this->username);
        $this->logger->stage(DeployLogger::STAGE_RUNNING);
    }

    private function tearDownHarness(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function putFile(string $relative, string $contents): void
    {
        $path = $this->dir.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function record(string $kind, mixed $payload): void
    {
        $this->events[] = '['.count($this->logger?->entries() ?? []).' log lines] '.$kind."\n"
            .(is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function snapshot(): string
    {
        $lines = [];
        foreach ($this->logger?->entries() ?? [] as $i => $entry) {
            $lines[] = "#{$i} {$entry['level']}: {$entry['msg']}";
        }

        return str_replace(
            [$this->dir, $this->username],
            ['{dir}', '{user}'],
            implode("\n\n", $this->events)."\n\n== log\n".implode("\n", $lines)."\n"
        );
    }

    private function assertMatchesGolden(string $name, ?string $actual = null): void
    {
        $actual ??= $this->snapshot();
        $path = __DIR__.'/golden/'.$name.'.txt';
        $this->assertFileExists($path);
        $this->assertSame((string) file_get_contents($path), $actual);
    }

    private function recordingSystem(): System
    {
        $harness = $this;

        return new class($harness) extends System
        {
            public function __construct(private object $harness) {}

            public function filesystem(): SystemFilesystem
            {
                return new class($this, $this->harness) extends SystemFilesystem
                {
                    public function __construct(System $engine, private object $harness)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return file_exists($path);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return (string) file_get_contents($path);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->harness->record("write {$path} chown={$chown} mode={$chmod}", $contents);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                $this->harness->record("exec {$line}", '');

                return match (true) {
                    str_contains($line, '/etc/passwd') => "root:x:0:0:root:/root:/bin/bash\n",
                    str_contains($line, '/etc/group') => "root:x:0:\n",
                    default => '',
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->harness->record('process '.(is_array($cmd) ? implode(' ', $cmd) : $cmd), '');

                return SuccessfulProcess::for($cmd);
            }
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}|null  $sidecars
     */
    private function harnessDind(
        array $details = [],
        ?array $sidecars = null,
        ?bool $entrypointAnswer = null,
        ?InnerDocker $innerDocker = null,
        ?HostCompile $hostCompile = null,
        ?string $publicUrl = 'https://acme.example.test',
        ?string $composeSource = null
    ): Dind {
        $model = new User;
        $model->username = $this->username;
        $model->details = $details;
        $system = $this->recordingSystem();

        $writer = $this->createStub(ComposeWriter::class);
        $writer->method('writeGeneratedCompose')->willReturnCallback(function (string $dir, string $yaml, ?string $chown = null): void {
            $this->record("compose {$dir} chown={$chown}", $yaml);
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('composeFilePath')->willReturn('/home/u/docker-compose.yml');
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('publicAppUrl')->willReturn($publicUrl);
        $dind->method('userAppDirPath')->willReturn($this->dir);
        $dind->method('composeWriter')->willReturn($writer);
        $dind->method('userAppComposeFilePath')->willReturn($this->dir.'/docker-compose.panelalpha.yml');
        $dind->method('userAppExistingComposeFilePath')->willReturn($composeSource);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $dind->method('innerDocker')->willReturn($innerDocker ?? $this->createStub(InnerDocker::class));
        $dind->method('hostCompile')->willReturn($hostCompile ?? $this->createStub(HostCompile::class));
        $dind->method('strategy')->willReturn(new HarnessDeployStrategy(
            $dind,
            $sidecars ?? ['services' => [], 'volumes' => [], 'env' => []],
            $entrypointAnswer,
            fn (array $call) => $this->record(array_shift($call), $call)
        ));

        return $dind;
    }
}
