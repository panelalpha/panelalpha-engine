<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\SilentPortCheck;
use Illuminate\Support\Facades\Log;
use Tests\Support\ComposePsFixture;
use Tests\TestCase;

/**
 * The whole result SilentPortCheck::check() returns, and its silence when the
 * containers cannot be asked.
 */
class SilentPortCheckResultTest extends TestCase
{
    private const PS = '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":8080,"PublishedPort":8080}]}' . "\n"
        . '{"Name":"project-worker-1","Service":"worker","State":"running","Publishers":[{"TargetPort":9000,"PublishedPort":9000}]}';

    public function test_every_silent_publisher_is_diagnosed_and_the_unbound_ones_named_once(): void
    {
        $check = $this->check(
            [['port' => 8080, 'status' => AppHealth::STATUS_FAIL], ['port' => 9000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
            "  sl  local_address rem_address   st\n   0: 0B00007F:A1B2 00000000:0000 0A 00000000:00000000 00:00000000 00000000  0 0 1 1\n"
        );

        $this->assertNotNull($check);
        $findings = $check['evidence']['silent'];
        $this->assertCount(2, $findings);
        $this->assertSame([
            'id' => SilentPortCheck::ID,
            'group' => 'runtime',
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application is running but does not answer on the port it publishes.',
            'detail' => implode(' ', $findings),
            'fix' => 'Its output is in the deploy log and in the container output.',
            'evidence' => ['silent' => $findings, 'starting' => ['app', 'worker']],
        ], $check);
    }

    public function test_containers_that_cannot_be_asked_give_no_verdict(): void
    {
        Log::spy();

        $this->assertNull($this->check([['port' => 8080, 'status' => AppHealth::STATUS_FAIL]], null));
        Log::shouldHaveReceived('debug')->once()->with('Silent-port check could not run: Cannot connect to the Docker daemon');
    }

    /** Sockets the daemon cannot read are no sign the app listens on nothing. */
    public function test_sockets_the_daemon_cannot_read_give_no_verdict(): void
    {
        Log::spy();

        $this->assertNull($this->check([['port' => 8080, 'status' => AppHealth::STATUS_FAIL]], '', 'Error response from daemon: No such container: project-app-1'));
        Log::shouldHaveReceived('debug')->once()->with('Silent-port check could not run: Error response from daemon: No such container: project-app-1');
    }

    /** @param list<array<string, mixed>> $results */
    private function check(array $results, ?string $procNet, ?string $socketsRefused = null): ?array
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $cmd) use ($procNet, $socketsRefused): string {
            if ($procNet === null) {
                throw new \RuntimeException('Cannot connect to the Docker daemon');
            }
            if (! str_contains(implode(' ', $cmd), 'account-docker.listeningSockets')) {
                return ComposePsFixture::answer($cmd, self::PS) ?? self::PS;
            }
            if ($socketsRefused !== null) {
                throw new DockerErrorException($socketsRefused);
            }

            return $procNet;
        });

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('userAppComposeProjectName')->willReturn('project');

        return (new SilentPortCheck($dind))->check($results);
    }
}
