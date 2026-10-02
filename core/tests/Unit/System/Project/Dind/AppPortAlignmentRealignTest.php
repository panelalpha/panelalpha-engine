<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * engine#88, the rewrite path: php-fpm on 9000 outranks the real server on
 * 8081 and never answers HTTP, so the run file must be pointed at 8081.
 * A booted app, because realigning records a Telemetry signal.
 */
class AppPortAlignmentRealignTest extends TestCase
{
    private const RUN_PATH = '/home/acme/project/docker-compose.panelalpha.yml';

    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    /** @var array<string, string> */
    private array $copiedTo = [];

    /** @var list<string> */
    private array $probed = [];

    public function test_the_run_file_is_pointed_at_the_candidate_that_answers_http(): void
    {
        $this->alignWith([9000 => '000', 8081 => '200']);

        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertArrayHasKey(self::RUN_PATH, $this->copiedTo, 'the run file was never rewritten');
        $this->assertStringContainsString('8080:8081', $this->copiedTo[self::RUN_PATH]);
    }

    public function test_nothing_is_rewritten_when_no_candidate_answers_http(): void
    {
        $this->alignWith([9000 => '000', 8081 => '000']);

        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertSame([], $this->copiedTo);
    }

    /** @param array<int, string> $statuses port => what the probe prints */
    private function alignWith(array $statuses): void
    {
        $rows = [self::HEADER];
        foreach (array_keys($statuses) as $i => $port) {
            $rows[] = sprintf('%4d: 00000000:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1', $i, $port);
        }
        $files = [self::RUN_PATH => "services:\n  app:\n    ports:\n      - \"8080:8080\"\n"];
        $copiedTo = &$this->copiedTo;
        $probed = &$this->probed;

        $system = new class ($files, $copiedTo, $probed, implode("\n", $rows) . "\n", $statuses) extends System {
            /**
             * @param array<string, string> $files
             * @param array<string, string> $copiedTo
             * @param list<string> $probed
             * @param array<int, string> $statuses
             */
            public function __construct(
                private array $files,
                private array &$copiedTo,
                private array &$probed,
                private string $procNetTcp,
                private array $statuses,
            ) {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;

                return new class ($this, $files) extends SystemFilesystem {
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
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'net/tcp')) {
                    return $this->procNetTcp;
                }
                if (str_contains($line, 'curl') && preg_match('/ip:(\d+)\//', $line, $m) === 1) {
                    $this->probed[] = $m[1];

                    return $this->statuses[(int) $m[1]] ?? '000';
                }
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };

        $user = new User();
        $user->username = 'acme';
        $user->details = ['UID' => 1001, 'GID' => 1001];
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('userModel')->willReturn($user);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $dind->method('userAppComposeFileToRun')->willReturn(self::RUN_PATH);
        $dind->method('userAppComposeCommand')->willReturn(['docker', 'compose', 'up', '-d', '--no-build']);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        (new AppPortAlignment($dind, static function (int $seconds): void {
        }))->alignIfNeeded();
    }
}
