<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\BindSourceDirectories;
use App\System\Project\Dind\ProjectEnvironment;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * MediaStationGo binds ./media and ./downloads with create_host_path: false, so `up` refused a fresh
 * checkout with "bind source path does not exist"; the account creates them first.
 */
class BindSourceDirectoriesTest extends TestCase
{
    private string $username = '';

    private string $root = '';

    private string $project = '';

    /** @var list<list<string>> the commands run as the account */
    private array $asAccount = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'binddir' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->root = sys_get_temp_dir() . '/pa-binddir-' . bin2hex(random_bytes(4));
        $this->project = $this->root . '/project';
        mkdir($this->project . '/media', 0755, true);
        file_put_contents($this->project . '/media/kept.mkv', 'x');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->root]))->run();
        parent::tearDown();
    }

    public function test_missing_directories_are_created_by_the_account_and_existing_ones_left_alone(): void
    {
        $lines = $this->create(self::compose(['./media', './downloads', '${DATA_DIR:-./var/data}', './cert.pem']));

        $this->assertDirectoryExists($this->project . '/downloads');
        $this->assertDirectoryExists($this->project . '/var/data');
        $this->assertFileDoesNotExist($this->project . '/cert.pem');
        $this->assertFileExists($this->project . '/media/kept.mkv');
        $this->assertContains('Created downloads, var/data, which the compose file binds with create_host_path: false', $lines, implode("\n", $lines));
        $this->assertSame(['su', '-s', '/bin/bash', $this->username], array_slice($this->asAccount[0], 0, 4));
    }

    public function test_a_committed_symlink_never_leads_the_creation_outside_the_checkout(): void
    {
        mkdir($this->root . '/outside', 0755);
        symlink($this->root . '/outside', $this->project . '/linked');

        $lines = $this->create(self::compose(['./linked/data']));

        $this->assertDirectoryDoesNotExist($this->root . '/outside/data');
        $this->assertSame([], array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Created ')));
    }

    public function test_nothing_runs_when_no_bind_refuses_host_path_creation(): void
    {
        $this->create("services:\n  app:\n    image: nginx\n    volumes:\n      - ./site:/usr/share/nginx/html\n");

        $this->assertSame([], $this->asAccount);
        $this->assertDirectoryDoesNotExist($this->project . '/site');
    }

    /** @param list<string> $sources */
    private static function compose(array $sources): string
    {
        $yaml = "services:\n  app:\n    image: ghcr.io/shukebta/mediastation-go:latest\n    volumes:\n";
        foreach ($sources as $at => $source) {
            $yaml .= "      - type: bind\n        source: \"{$source}\"\n        target: /mnt/{$at}\n"
                . "        bind:\n          create_host_path: false\n";
        }

        return $yaml;
    }

    /** @return list<string> the deploy log after create() */
    private function create(string $compose): array
    {
        $composeFile = $this->root . '/docker-compose.run.yml';
        file_put_contents($composeFile, $compose);
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $noteAccount = function (array $cmd): void {
            $this->asAccount[] = $cmd;
        };
        $system = new class ($noteAccount) extends System {
            public function __construct(private readonly \Closure $noteAccount) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(LocalRoot::argv((array) $cmd));
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $inAccount = LocalRoot::inAccount((array) $cmd);
                if ($inAccount === []) {
                    $process = new Process(LocalRoot::argv((array) $cmd));
                } else {
                    ($this->noteAccount)($inAccount);
                    $process = new Process(['bash', '-c', $inAccount[5]]);
                }
                $process->mustRun();

                return $process->getOutput();
            }
        };

        $environment = $this->createStub(ProjectEnvironment::class);
        $environment->method('forInterpolation')->willReturn([]);
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($this->createStub(User::class));
        $dind->method('userAppComposeFileToRun')->willReturn($composeFile);
        $dind->method('userAppDirPath')->willReturn($this->project);
        $dind->method('environment')->willReturn($environment);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        (new BindSourceDirectories($dind))->create();

        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }
}
