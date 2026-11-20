<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Health\ProbedResponse;
use App\System\Project\Dind\AppHealth;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppHealthOutputsPinnedFixtures;
use Tests\Support\TestNetwork;

/**
 * What AppHealth answers, output for output: the probe scripts byte for byte, the parsed
 * port results, the restart, stop and blank-page checks, and every way check() can end.
 */
class AppHealthOutputsPinnedTest extends TestCase
{
    use AppHealthOutputsPinnedFixtures;
    use MockeryPHPUnitIntegration;

    /** @return array<string, array{0: list<int>, 1: int, 2: int, 3: int, 4: ?string, 5: bool, 6: list<int>}> */
    public static function probeScripts(): array
    {
        return [
            'defaults' => [[8080], 5, 3, 2, null, false, []],
            'domain, https port, deploy wait' => [[8080, 8443], 4, 2, 1, 'Shop.Example.com', true, [8443]],
            'nonsense tuning, blank domain' => [[0, 3000], 0, 0, -1, '  ', false, [3000]],
        ];
    }

    /**
     * @param  list<int>  $ports
     * @param  list<int>  $https
     */
    #[DataProvider('probeScripts')]
    public function test_the_probe_script_is_unchanged(array $ports, int $timeout, int $attempts, int $delay, ?string $domain, bool $wait, array $https): void
    {
        $script = AppHealth::probeScript($ports, $timeout, $attempts, $delay, $domain, $wait, $https);

        $this->pin('probe '.implode(',', $ports), ['bytes' => strlen($script), 'sha256' => hash('sha256', $script)]);
    }

    public function test_the_path_probe_script_is_unchanged(): void
    {
        $script = AppHealth::pathProbeScript('https', 8443, ['/login', '/a b'], 3, ' Shop.Example.com ');

        $this->pin('path probe', ['bytes' => strlen($script), 'sha256' => hash('sha256', $script)]);
    }

    public function test_probe_output_parses_as_before(): void
    {
        $raw = "8000\thttp\t200 0.0123456\t\t" . base64_encode('<p>hi</p>') . "\t/login\n"
            . "\n"
            . "0\thttp\t200 0.1\n"
            . "short\tline\n"
            . "8443\t\t000 0\tconnection refused\n"
            . "9000\thttps\t502 1.5\t\tnot-base64!\tnowhere\n";

        $this->pin('parsed', $this->normalised(AppHealth::parseProbeOutput($raw, [9000, 8000, 8443, 7000])));
    }

    public function test_restart_loop_checks_are_unchanged(): void
    {
        $before = [self::container('/project-app-1', 'app', 'running', 0, 0), self::container('/project-db-1', 'db', 'running', 0, 2)];
        $app = [
            self::container('/project-app-1', 'app', 'exited', 1, 1),
            self::container('/project-db-1', 'db', 'running', 0, 2),
            self::container('/project-worker-1', null, 'restarting', 3, 7),
        ];
        $sidecar = [self::container('/project-app-1', 'app', 'running', 0, 0), self::container('/project-db-1', 'db', 'restarting', 1, 3)];

        $this->pin('app loop', AppHealth::restartLoopBetween($before, $app, ['app']));
        $this->pin('unknown app loop', AppHealth::restartLoopBetween(null, $app));
        $this->pin('sidecar loop', AppHealth::restartLoopBetween($before, $sidecar, ['app']));
        $this->assertNull(AppHealth::restartLoopBetween($before, $before, ['app']));
    }

    public function test_the_stopped_check_is_unchanged(): void
    {
        $rows = self::COMPOSE_PS_STOPPED . "\n"
            . '{"Name":"project-ready-1","Service":"ready","State":"exited","Status":"Exited (0) 1 hour ago","ExitCode":0}' . "\n"
            . '{"Name":"project-cache-1","State":"paused","ExitCode":0}' . "\n"
            . '{"State":"created"}';

        $this->pin('stopped', AppHealth::stoppedFrom($rows, ['ready']));
    }

    public function test_the_blank_page_check_is_unchanged(): void
    {
        $front = new ProbedResponse(200, '  ', 'http://' . TestNetwork::LOOPBACK . ':8000/', 0.1);
        $warned = ['serving' => 'serving', 'checks' => [['id' => 'x', 'status' => 'fail', 'severity' => 'warning']]];
        $failed = ['serving' => 'error_page', 'checks' => [['id' => 'y', 'status' => 'fail', 'severity' => 'error']]];

        $this->pin('blank php', $this->normalised(AppHealth::withBlankPageCheck($warned, 'php', $front)));
        $this->pin('blank outranked', $this->normalised(AppHealth::withBlankPageCheck($failed, 'node', $front)));
        $this->assertSame($warned, AppHealth::withBlankPageCheck($warned, 'php', new ProbedResponse(204, '', 'u', 0.1)));
    }

    public function test_an_unreadable_compose_file_is_a_failed_check(): void
    {
        $health = $this->health(static fn (): array => throw new \RuntimeException("compose gone\nsecond line"), '');

        $this->pin('compose unreadable', $health->check());
        $this->assertSame([], $this->ran);
    }

    public function test_no_port_is_nothing_to_probe(): void
    {
        $this->assertSame(['healthy' => null, 'ports' => []], $this->health(static fn (): array => [], '')->check());
        $this->assertSame([], $this->ran);
    }

    /** @return array<string, mixed> the account daemon's inspect of a container */
    private static function container(string $name, ?string $service, string $status, int $exitCode, int $restarts): array
    {
        return [
            'Name' => $name,
            'Config' => ['Labels' => $service === null ? [] : ['com.docker.compose.service' => $service]],
            'State' => ['Status' => $status, 'ExitCode' => $exitCode],
            'RestartCount' => $restarts,
        ];
    }
}
