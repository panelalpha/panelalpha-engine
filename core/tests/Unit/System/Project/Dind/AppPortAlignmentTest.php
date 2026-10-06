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
     * @param string|array<string, string> $httpStatus one answer, or one per probed port
     */
    private function stubbedSystem(array $files, string $procNetTcp, string|array $httpStatus = ''): System
    {
        $copiedTo = &$this->copiedTo;

        return new class ($files, $copiedTo, $procNetTcp, $httpStatus) extends System {
            /**
             * @param array<string, string> $files
             * @param array<string, string> $copiedTo
             */
            public function __construct(
                private array $files,
                private array &$copiedTo,
                private string $procNetTcp,
                private string|array $httpStatus,
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
                if (str_contains($line, 'curl')) {
                    if (is_array($this->httpStatus)) {
                        preg_match('/ip:(\d+)\//', $line, $m);

                        return $this->httpStatus[$m[1] ?? ''] ?? '000';
                    }

                    return $this->httpStatus;
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

    /**
     * Engine #88: sockets over time, one list per poll; the last repeats.
     *
     * @param list<list<int>> $timeline
     * @param list<int> $declared
     * @return array{0: ?int, 1: int, 2: list<int>} port, polls, sleeps
     */
    private function awaitOver(int $expected, array $timeline, array $declared = []): array
    {
        $polls = 0;
        $sleeps = [];
        $port = AppPortAlignment::awaitPort(
            $expected,
            function () use ($timeline, &$polls): array {
                $ports = $timeline[min($polls++, count($timeline) - 1)];

                return array_map(fn (int $p): array => ['addr' => '00000000', 'port' => $p], $ports);
            },
            function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            $declared
        );

        return [$port, $polls, $sleeps];
    }

    public function test_it_returns_at_once_when_the_expected_port_is_bound(): void
    {
        [$port, $polls, $sleeps] = $this->awaitOver(8081, [[4416, 8081]]);

        $this->assertNull($port);
        $this->assertSame(1, $polls);
        $this->assertSame([], $sleeps);
    }

    /** MeTube: the helper on 4416 binds before the server on 8081. */
    public function test_a_helper_bound_first_does_not_win_over_the_expected_port(): void
    {
        [$port, $polls] = $this->awaitOver(8081, [[], [4416], [4416], [4416, 8081]]);

        $this->assertNull($port);
        $this->assertSame(4, $polls);
    }

    /** php-fpm on 9000 comes up before nginx on 80. */
    public function test_php_fpm_bound_first_does_not_win_over_the_expected_port(): void
    {
        [$port] = $this->awaitOver(80, [[9000], [9000, 80]]);

        $this->assertNull($port);
    }

    public function test_it_forwards_to_the_candidate_only_after_the_whole_window(): void
    {
        [$port, $polls, $sleeps] = $this->awaitOver(8081, [[], [4416]]);

        $this->assertSame(4416, $port);
        $this->assertSame(12, $polls);
        $this->assertSame(array_fill(0, 11, 2), $sleeps);
    }

    public function test_a_candidate_survives_a_poll_that_saw_nothing(): void
    {
        [$port] = $this->awaitOver(8081, [[3000], [3000], []]);

        $this->assertSame(3000, $port);
    }

    /**
     * SignServer CE / #88: the only other socket in the window does not speak
     * HTTP (epmd, php-fpm, a loopback-only observer). Forwarding there leaves
     * the site dead for good, so the published port stays.
     */
    public function test_a_candidate_that_does_not_answer_http_is_not_forwarded_to(): void
    {
        $files = [self::RUN_PATH => "services:\n  app:\n    ports:\n      - \"8081:8081\"\n"];
        $system = $this->stubbedSystem($files, $this->procNetListening(8090), '000');
        $dind = $this->stubbedDind($system, self::RUN_PATH, self::RUN_PATH);

        // Reaching the rewrite would call Telemetry, which errors without Laravel.
        (new AppPortAlignment($dind, static function (int $seconds): void {
        }))->alignIfNeeded();

        $this->assertSame([], $this->copiedTo, 'a port that does not answer HTTP must not become the published one');
    }

    public function test_a_probe_answer_reads_as_http_or_not(): void
    {
        $this->assertTrue(AppPortAlignment::answersHttp("404\n"));
        $this->assertTrue(AppPortAlignment::answersHttp('200'));
        $this->assertFalse(AppPortAlignment::answersHttp("000\n"));
        $this->assertNull(AppPortAlignment::answersHttp(''), 'could not ask: no verdict, align as before');
        $this->assertNull(AppPortAlignment::answersHttp('curl: not found'));
    }

    /** epmd and unprivileged SSH are never front doors (#259). */
    public function test_non_web_ports_are_never_chosen(): void
    {
        [$port] = $this->awaitOver(4000, [[4369, 2222]]);

        $this->assertNull($port);
    }

    /** rapidbay never binds 6881 over TCP; 80 is a stock nginx page, 5000 the declared UI. */
    public function test_a_port_the_dockerfile_declares_wins_over_the_generic_preference(): void
    {
        [$port] = $this->awaitOver(6881, [[80, 5000]], [6881, 5000]);

        $this->assertSame(5000, $port);
    }

    /** engine#88: php-fpm on 9000 outranks the real server on 8081, and is never HTTP. */
    public function test_the_next_candidate_is_tried_when_the_first_does_not_answer_http(): void
    {
        $probed = [];
        $port = AppPortAlignment::firstAnsweringHttp([9000, 8081], function (int $port) use (&$probed): ?bool {
            $probed[] = $port;

            return $port === 8081;
        });

        $this->assertSame(8081, $port);
        $this->assertSame([9000, 8081], $probed);
    }

    public function test_no_candidate_is_chosen_when_none_answers_http(): void
    {
        $this->assertNull(AppPortAlignment::firstAnsweringHttp([9000, 4000], fn (): bool => false));
        // A probe that could not run is no verdict: align as before.
        $this->assertSame(9000, AppPortAlignment::firstAnsweringHttp([9000, 8081], fn (): ?bool => null));
    }

    public function test_only_the_first_few_candidates_are_probed(): void
    {
        $probed = [];
        AppPortAlignment::firstAnsweringHttp([9000, 4000, 5555, 6666, 7777], function (int $port) use (&$probed): bool {
            $probed[] = $port;

            return false;
        });

        $this->assertSame([9000, 4000, 5555], $probed);
    }

    public function test_the_window_ends_with_no_candidate_once_the_container_stopped(): void
    {
        $polls = 0;
        $candidates = AppPortAlignment::awaitCandidates(
            8080,
            function () use (&$polls): array {
                $polls++;

                return [['addr' => '00000000', 'port' => 9000]];
            },
            static function (int $seconds): void {
            },
            [],
            function () use (&$polls): bool {
                return $polls >= 2;
            }
        );

        $this->assertSame([], $candidates);
        $this->assertSame(2, $polls);
    }

    public function test_the_window_hands_back_every_candidate_best_first(): void
    {
        $candidates = AppPortAlignment::awaitCandidates(
            8080,
            fn (): array => [['addr' => '00000000', 'port' => 9000], ['addr' => '00000000', 'port' => 8081]],
            static function (int $seconds): void {
            }
        );

        $this->assertSame([9000, 8081], $candidates);
    }
}
