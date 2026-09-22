<?php

namespace Tests\Unit\System\Project\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * Ticket 04: port detection has to read the run file the engine generated
 * (ADR-0001), not a client-owned compose file that happens to sit beside it
 * -- reading the wrong one would realign a port from stale or foreign
 * content, or silently do nothing where it should have acted.
 *
 * The fixtures below put a *different*, deliberately mismatched port at the
 * neighbouring client path: if `alignIfNeeded()` ever read that file instead
 * of the run file, the mismatch it saw would not match the sockets given
 * here, sending it down the realignment-write path -- which also calls
 * {@see \App\Lib\Deploy\Telemetry\Telemetry::signal()}, itself dependent on
 * a bootstrapped Laravel container this bare PHPUnit process does not
 * provide. So a wrong read here does not pass quietly; it errors loudly.
 * The case actually driven -- the run file's declared port already matches
 * what is being served -- resolves before that call and needs no Laravel
 * bootstrap.
 */
class AppPortAlignmentTest extends TestCase
{
    private const RUN_PATH = '/home/acme/project/docker-compose.panelalpha.yml';

    private const CLIENT_PATH = '/home/acme/project/docker-compose.yml';

    private const HEADER =
        '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode';

    /** @var array<string, string> target path => contents, from every `sudo cp` a write issued */
    private array $copiedTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->copiedTo = [];
    }

    /** One /proc/net/tcp row in LISTEN state, non-loopback. */
    private function procNetListening(int $port): string
    {
        $row = sprintf(
            '%4d: %s:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1',
            0,
            '00000000',
            $port
        );

        return self::HEADER . "\n" . $row . "\n";
    }

    /**
     * @param array<string, string> $files
     */
    private function stubbedSystem(array $files, string $procNetTcp): System
    {
        $copiedTo = &$this->copiedTo;

        return new class ($files, $copiedTo, $procNetTcp) extends System {
            /**
             * @param array<string, string> $files
             * @param array<string, string> $copiedTo
             */
            public function __construct(
                private array $files,
                private array &$copiedTo,
                private string $procNetTcp,
            ) {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;
                $engine = $this;

                return new class ($engine, $files) extends SystemFilesystem {
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
                // The port-settle script cats /proc/$pid/net/tcp[6]; answer it
                // directly rather than needing a real docker daemon.
                if (str_contains($line, 'net/tcp')) {
                    return $this->procNetTcp;
                }
                if (preg_match('/^sudo cp (\S+) (\S+)$/', $line, $m) === 1 && is_file($m[1])) {
                    $this->copiedTo[$m[2]] = (string) file_get_contents($m[1]);
                }

                return '';
            }
        };
    }

    private function stubbedDind(System $system, string $composeFilePath, string $composeFileToRun): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('userAppComposeFilePath')->willReturn($composeFilePath);
        $dind->method('userAppComposeFileToRun')->willReturn($composeFileToRun);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }

    /**
     * When the run file is not the file this strategy would run at all, the
     * method must not touch the filesystem or attempt any realignment.
     */
    public function test_it_writes_nothing_when_the_run_file_is_not_the_one_that_runs(): void
    {
        $files = [
            self::RUN_PATH => "services:\n  app:\n    ports:\n      - \"8080:4321\"\n",
            self::CLIENT_PATH => "services:\n  app:\n    ports:\n      - \"8080:5555\"\n",
        ];
        $system = $this->stubbedSystem($files, $this->procNetListening(4321));
        // A different value than userAppComposeFilePath(): the guard must fire.
        $dind = $this->stubbedDind($system, self::RUN_PATH, '/home/acme/project/some-other-file.yml');

        (new AppPortAlignment($dind))->alignIfNeeded();

        $this->assertSame([], $this->copiedTo, 'nothing should be written when the strategy guard declines');
    }

    /**
     * The headline promise: detection reads the engine's own run file, not a
     * same-directory client compose file, even when both exist.
     *
     * The run file declares the port that is actually served (4321); the
     * client file beside it declares a different one (5555) that the sockets
     * below do not serve. Reading the client file by mistake would see a
     * mismatch, attempt a realignment write, and hit the Telemetry call this
     * test cannot support -- so a passing, non-throwing run with no write is
     * only possible if the run file, not the client file, was read.
     */
    public function test_it_reads_the_run_file_not_the_client_compose_file(): void
    {
        $files = [
            self::RUN_PATH => "services:\n  app:\n    ports:\n      - \"8080:4321\"\n",
            self::CLIENT_PATH => "services:\n  app:\n    ports:\n      - \"8080:5555\"\n",
        ];
        $system = $this->stubbedSystem($files, $this->procNetListening(4321));
        $dind = $this->stubbedDind($system, self::RUN_PATH, self::RUN_PATH);

        (new AppPortAlignment($dind))->alignIfNeeded();

        $this->assertArrayNotHasKey(self::CLIENT_PATH, $this->copiedTo, "the client's compose file must never be written to");
        $this->assertSame(
            [],
            $this->copiedTo,
            'the port already served (4321) matches the run file, so nothing should be rewritten'
        );
    }
}
