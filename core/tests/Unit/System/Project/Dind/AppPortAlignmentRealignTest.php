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
 * The rewrite path: php-fpm on 9000 outranks the real server on
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

    /** @var list<string> */
    private array $ran = [];

    public function test_the_run_file_is_pointed_at_the_candidate_that_answers_http(): void
    {
        $this->alignWith([9000 => '000', 8081 => '200']);

        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertArrayHasKey(self::RUN_PATH, $this->copiedTo, 'the run file was never rewritten');
        $this->assertStringContainsString('8080:8081', $this->copiedTo[self::RUN_PATH]);
        $this->assertNotEmpty($this->ranMatching('up -d --no-build'), 'the app is started again on it');
        $this->assertNotEmpty($this->ranMatching('ps -q app'), 'the app\'s own container is measured');
    }

    public function test_a_redeploys_second_copy_is_measured_itself_and_started_again_by_the_caller(): void
    {
        $realigned = $this->alignWith([9000 => '000', 8081 => '200'], 'next1');

        $this->assertTrue($realigned);
        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertStringContainsString('8080:8081', $this->copiedTo[self::RUN_PATH] ?? '');
        $measured = $this->ranMatching('cid=');
        $this->assertNotEmpty($measured);
        foreach ($measured as $line) {
            $this->assertStringContainsString("cid='next1'", $line, 'the copy, not the running app');
            $this->assertStringNotContainsString('ps -q app', $line);
        }
        $this->assertSame([], $this->ranMatching('up -d'));
    }

    /** A copy that stopped running is not waited on for the whole window, and nothing is rewritten. */
    public function test_the_wait_for_a_second_copy_ends_once_it_stops_running(): void
    {
        $asked = 0;
        $realigned = $this->alignWith([9000 => '000', 8081 => '200'], 'next1', function () use (&$asked): bool {
            $asked++;

            return true;
        });

        $this->assertFalse($realigned);
        $this->assertSame(1, $asked);
        $this->assertCount(1, $this->ranMatching('net/tcp'));
        $this->assertSame([], $this->probed);
        $this->assertSame([], $this->copiedTo);
    }

    public function test_a_second_copy_on_the_port_the_run_file_names_changes_nothing(): void
    {
        $this->assertFalse($this->alignWith([8080 => '200'], 'next1'));
        $this->assertSame([], $this->copiedTo);
    }

    public function test_nothing_is_rewritten_when_no_candidate_answers_http(): void
    {
        $this->alignWith([9000 => '000', 8081 => '000']);

        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertSame([], $this->copiedTo);
    }

    /** @return list<string> */
    private function ranMatching(string $needle): array
    {
        return array_values(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)));
    }

    /**
     * @param array<int, string> $statuses port => what the probe prints
     * @param (\Closure(): bool)|null $stopped
     */
    private function alignWith(array $statuses, ?string $container = null, ?\Closure $stopped = null): ?bool
    {
        $rows = [self::HEADER];
        foreach (array_keys($statuses) as $i => $port) {
            $rows[] = sprintf('%4d: 00000000:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1', $i, $port);
        }
        $files = [self::RUN_PATH => "services:\n  app:\n    ports:\n      - \"8080:8080\"\n"];
        $copiedTo = &$this->copiedTo;
        $probed = &$this->probed;
        $ran = &$this->ran;

        $system = new class ($files, $copiedTo, $probed, $ran, implode("\n", $rows) . "\n", $statuses) extends System {
            /**
             * @param array<string, string> $files
             * @param array<string, string> $copiedTo
             * @param list<string> $probed
             * @param list<string> $ran
             * @param array<int, string> $statuses
             */
            public function __construct(
                private array $files,
                private array &$copiedTo,
                private array &$probed,
                private array &$ran,
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
                $this->ran[] = $line;
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

        $alignment = new AppPortAlignment($dind, static function (int $seconds): void {
        });
        if ($container !== null) {
            return $alignment->alignTo($container, $stopped);
        }
        $alignment->alignIfNeeded();

        return null;
    }
}
