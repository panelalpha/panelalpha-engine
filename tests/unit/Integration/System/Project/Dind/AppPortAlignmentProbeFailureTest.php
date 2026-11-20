<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Integration\System\Project\SuccessfulProcess;
use Tests\Support\LocalRoot;
use Tests\Support\StagedRename;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The realignment when one of its own steps fails: a container that cannot be inspected or has no
 * address is no answer, and an app that cannot be started again on the new port is logged, not thrown.
 */
class AppPortAlignmentProbeFailureTest extends TestCase
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

    /** The app container's inspect; null makes the daemon fail. */
    private ?string $inspect = '[{"NetworkSettings":{"Networks":{"project_default":{"IPAddress":"%s"}}}}]';

    private bool $upFails = false;

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'alignfail' . bin2hex(random_bytes(3));
        $this->inspect = sprintf((string) $this->inspect, TestNetwork::container(5));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_an_app_that_cannot_be_started_again_on_the_new_port_is_logged(): void
    {
        Log::spy();
        $this->upFails = true;

        $this->align();

        $this->assertSame(['9000', '8081'], $this->probed);
        $this->assertStringContainsString('8080:8081', $this->copiedTo[self::RUN_PATH] ?? '');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'Could not align the published app port: compose up refused'
        )->once();
    }

    /** A probe that could not run does not rule HTTP out, so the first candidate is taken. */
    public function test_a_container_that_cannot_be_inspected_does_not_rule_its_first_port_out(): void
    {
        $this->inspect = null;

        $this->align();

        $this->assertSame([], $this->probed);
        $this->assertStringContainsString('8080:9000', $this->copiedTo[self::RUN_PATH] ?? '');
        $this->assertCount(1, $this->ranMatching('account-docker.containers'), 'asked once, for the first candidate');
    }

    public function test_a_container_with_no_address_is_not_probed_and_does_not_rule_its_first_port_out(): void
    {
        $this->inspect = '[{"NetworkSettings":{"Networks":{"bridge":{"IPAddress":""},"none":"not a network"}}}]';

        $this->align();

        $this->assertSame([], $this->probed);
        $this->assertStringContainsString('8080:9000', $this->copiedTo[self::RUN_PATH] ?? '');
    }

    /** @return list<string> */
    private function ranMatching(string $needle): array
    {
        return array_values(array_filter($this->ran, static fn (string $line): bool => str_contains($line, $needle)));
    }

    /** php-fpm's 9000 outranks the server on 8081, which is the one that answers HTTP. */
    private function align(): void
    {
        $procNetTcp = self::HEADER . "\n"
            . sprintf('%4d: 00000000:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1', 0, 9000) . "\n"
            . sprintf('%4d: 00000000:%04X 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1000 1', 1, 8081) . "\n";
        $answer = function (array $cmd) use ($procNetTcp): string {
            $line = implode(' ', $cmd);
            $this->ran[] = $line;
            $action = LocalRoot::action($cmd);
            if ($action === 'account-docker.listeningSockets') {
                return $procNetTcp;
            }
            if ($action === 'account-docker.containers') {
                return $this->inspect ?? throw new \RuntimeException('docker daemon not reachable');
            }
            if ($action === 'account-docker.listContainers') {
                return '[{"Id":"app1","Names":["/project-app-1"]}]';
            }
            if ($this->upFails && in_array('up', LocalRoot::composeCommand($cmd), true)) {
                throw new \RuntimeException('compose up refused');
            }
            if (str_contains($line, 'curl') && preg_match('/ probe \S+ (\d+)$/', $line, $m) === 1) {
                $this->probed[] = $m[1];

                return $m[1] === '8081' ? '200' : '000';
            }
            [$source, $target] = $action === 'files.copy' ? LocalRoot::values($cmd) : [null, null];
            if ($source !== null && is_file($source)) {
                $this->copiedTo[$target] = (string) file_get_contents($source);
            }
            $this->renameStaged($cmd);

            return '';
        };

        $system = new class ($answer, fn (array $cmd) => $this->renameStaged($cmd)) extends System {
            public function __construct(private \Closure $answer, private \Closure $rename)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this) extends SystemFilesystem {
                    public function isSymlink(string $path): bool
                    {
                        return false;
                    }

                    public function fileExists(string $path): bool
                    {
                        return $path === AppPortAlignmentProbeFailureTest::runPath();
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $path === AppPortAlignmentProbeFailureTest::runPath() ? "services:\n  app:\n    ports:\n      - \"8080:8080\"\n" : '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return ($this->answer)((array) $cmd);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                ($this->rename)((array) $cmd);

                return SuccessfulProcess::for($cmd);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }
        };

        $user = new User();
        $user->username = 'acme';
        $user->details = ['UID' => 1001, 'GID' => 1001];
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $dind->method('userAppComposeFileToRun')->willReturn(self::RUN_PATH);
        $dind->method('userAppComposeCommand')->willReturn(['docker', 'compose', 'up', '-d', '--no-build']);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeProjectName')->willReturn('project');

        (new AppPortAlignment($dind, static function (int $seconds): void {
        }))->alignIfNeeded();
    }

    public static function runPath(): string
    {
        return self::RUN_PATH;
    }

    /** @param list<string> $cmd */
    private function renameStaged(array $cmd): void
    {
        [$staged, $target] = StagedRename::of($cmd) ?? [null, null];
        if ($staged !== null && isset($this->copiedTo[$staged])) {
            $this->copiedTo[$target] = $this->copiedTo[$staged];
            unset($this->copiedTo[$staged]);
        }
    }
}
