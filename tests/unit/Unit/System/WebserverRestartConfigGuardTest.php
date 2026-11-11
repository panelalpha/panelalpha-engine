<?php

namespace Tests\Unit\System;

use App\System;
use App\System as EngineSystem;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A restart is the one webserver path with no fallback.
 *
 * sites-http crash-looped, and every operation that reaches into it
 * answered 422 `container is restarting`. A reload is not the cause -- a master
 * handed an unusable config keeps serving the one it booted with. A *restart*
 * is: the container exits on the `[emerg]` and loops under `restart: always`.
 * On the app-nginx image, a vhost naming a certificate that went with a deleted
 * account takes it down with `cannot load certificate`, which is the breakage
 * `pruneDomainConfigs()` exists to remove.
 *
 * So every path that restarts the container tests the config first -- the
 * queued one included, where the broker runs the test next to the restart. The
 * other half matters as much: only nginx's own verdict refuses, because a
 * container that is down is exactly what the restart is for.
 */
class WebserverRestartConfigGuardTest extends TestCase
{
    private const EMERG = 'nginx: [emerg] cannot load certificate "/etc/letsencrypt/live/gone/fullchain.pem": '
        . 'BIO_new_file() failed (SSL: error:80000002:system library::No such file or directory)';

    public function test_a_config_that_passes_is_not_a_problem(): void
    {
        $this->assertNull(Webserver::configProblemFrom(0, "nginx: configuration file /etc/nginx/nginx.conf test is successful\n"));
    }

    public function test_a_config_the_webserver_refuses_is_reported(): void
    {
        $problem = Webserver::configProblemFrom(1, self::EMERG);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('cannot load certificate', $problem);
    }

    public function test_a_failed_test_without_an_emerg_line_is_still_a_verdict(): void
    {
        $this->assertNotNull(Webserver::configProblemFrom(
            1,
            "nginx: configuration file /etc/nginx/nginx.conf test failed\n"
        ));
    }

    /**
     * The verdict goes into a log line, so it is bounded -- nginx can name
     * every vhost it read on the way to the one it refused.
     */
    public function test_the_reported_problem_is_bounded(): void
    {
        $problem = Webserver::configProblemFrom(1, 'nginx: [emerg] ' . str_repeat('x', 5000));

        $this->assertNotNull($problem);
        // Str::limit() cuts at 200 characters and appends its '...' marker.
        $this->assertLessThanOrEqual(203, mb_strlen($problem));
        $this->assertStringContainsString('[emerg]', $problem);
    }

    /** Compose is loud before nginx says anything, and the verdict is what matters. */
    public function test_the_verdict_survives_the_noise_in_front_of_it(): void
    {
        $noise = implode("\n", array_fill(0, 40, 'WARN[0000] /opt/panelalpha/shared-hosting/docker-compose.yml: '
            . 'the attribute `version` is obsolete, it will be ignored'));

        $problem = Webserver::configProblemFrom(1, $noise . "\n" . self::EMERG . "\nnginx: configuration file test failed");

        $this->assertNotNull($problem);
        $this->assertStringStartsWith('nginx: [emerg]', $problem);
        $this->assertStringContainsString('cannot load certificate', $problem);
    }

    #[DataProvider('unreachable')]
    public function test_nothing_to_ask_is_never_a_config_verdict(string $output): void
    {
        $this->assertNull(
            Webserver::configProblemFrom(1, $output),
            'a container that cannot be asked must not read as a broken config'
        );
    }

    /** @return array<string, array{string}> */
    public static function unreachable(): array
    {
        return [
            'stopped' => ['service "sites-http" is not running'],
            'no such service' => ['no such service: sites-http'],
            'restarting' => ['Error response from daemon: Container 3f2a is restarting, wait until the container is running'],
            'gone' => ['Error response from daemon: No such container: sites-http'],
            'silent' => [''],
            'paused' => ['Error response from daemon: Container 3f2a is paused, unpause the container before exec'],
            'stopping' => ['OCI runtime exec failed: exec failed: cannot exec in a stopped state: unknown'],
            'sudo' => ['sudo: a terminal is required to read the password'],
            'nsenter' => ['nsenter: cannot open /proc/1/ns/mnt: Permission denied'],
            'compose noise only' => ['WARN[0000] docker-compose.yml: the attribute `version` is obsolete'],
        ];
    }

    /**
     * The bug this file missed the first time: the guard sat on the reload
     * branch, and the branch that restarts the container -- the only one that
     * can crash-loop it -- went out unguarded.
     */
    public function test_the_queued_rebind_restart_asks_for_the_job_that_tests_the_config_first(): void
    {
        $commands = [];
        $this->system('nginx-proxy', $commands)->webserver()->scheduleWebserverReloadInBackground(true);

        $this->assertCount(1, $commands);
        $this->assertSame(
            '/usr/local/lib/panelalpha/pa-root --host webserver.queueReload -- /opt/panelalpha/shared-hosting/docker-compose.yml nginx-rebind',
            $commands[0]
        );
    }

    /** The method is documented non-blocking: nothing may be run before the queueing. */
    public function test_a_plain_reload_asks_for_the_reload_and_nothing_else(): void
    {
        $commands = [];
        $this->system('nginx-proxy', $commands)->webserver()->scheduleWebserverReloadInBackground();

        $this->assertCount(1, $commands);
        $this->assertSame(
            '/usr/local/lib/panelalpha/pa-root --host webserver.queueReload -- /opt/panelalpha/shared-hosting/docker-compose.yml nginx',
            $commands[0]
        );
    }

    /** A broker that could not be asked never runs the job, and nothing else would say so. */
    public function test_a_reload_the_broker_could_not_run_is_logged(): void
    {
        Log::spy();
        $commands = [];
        $this->system('nginx-proxy', $commands, 'echo "pa-root: refused: no such action" >&2; exit 77')
            ->webserver()->scheduleWebserverReloadInBackground();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'no such action'));
    }

    /**
     * A System whose webserver service has the real queueing code but a canned
     * host: nothing is run, and every host command is recorded.
     *
     * `exec()` is a hard failure so a detection that slipped back onto the host
     * reads as such rather than as a missing command.
     */
    private function system(string $webserver, array &$commands, string $hostAnswers = 'true'): System
    {
        $host = new class ($commands, $hostAnswers) extends System {
            public function __construct(private array &$commands, private string $hostAnswers)
            {
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [];
                $this->commands[] = implode(' ', LocalRoot::plain($argv) ?: $argv);
                $process = Process::fromShellCommandline($this->hostAnswers);
                $process->run();

                return $process;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                throw new \LogicException('webserver detection must not reach the host: '
                    . (is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }

            public function composeFilePath(): string
            {
                // Never read; it is escaped into the command that is recorded.
                return '/opt/panelalpha/shared-hosting/docker-compose.yml';
            }
        };

        $service = new class ($host, $webserver) extends Webserver {
            public function __construct(EngineSystem $system, private string $ws)
            {
                parent::__construct($system);
            }

            /** Fresh on both container-acting paths; the memo is left alone. */
            public function detectWebserver(): string
            {
                return $this->ws;
            }
        };

        return new class ($service) extends System {
            public function __construct(private Webserver $ws)
            {
            }

            public function webserver(): Webserver
            {
                return $this->ws;
            }
        };
    }
}
