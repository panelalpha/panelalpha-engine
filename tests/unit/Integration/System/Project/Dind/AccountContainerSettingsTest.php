<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountMemory;
use App\System\Project\Dind\OuterLifecycle;
use App\System\Project\Dind\Services\S6ServiceManager;
use App\System\Project\Dind\Services\SupervisordServiceManager;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Integration\System\Project\SuccessfulProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * What changing an account container's settings sends to the host: its memory limit, its services' s6 and
 * supervisord state, and the tenant firewall around a recreate.
 */
class AccountContainerSettingsTest extends TestCase
{
    private const COMPOSE = "services:\n  dind:\n    image: ghcr.io/panelalpha/engine-user-dind:v2.0.2\n    container_name: shop\n    mem_limit: 2048M\n    memswap_limit: 2048M\n";

    private string $username = '';

    /** @var list<list<string>> */
    private array $execs = [];

    /** @var list<list<string>> */
    private array $processes = [];

    /** @var array<string, string> */
    private array $written = [];

    private bool $firewallFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'acs'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_a_new_memory_limit_is_written_and_applied_to_the_running_container(): void
    {
        (new AccountMemory($this->dind(['memory_limit' => 768], running: true)))->apply();

        $compose = Yaml::parse($this->written['/opt/users/'.$this->username.'/docker-compose.yml']);
        $this->assertSame('768M', $compose['services']['dind']['mem_limit']);
        $this->assertSame('768M', $compose['services']['dind']['memswap_limit']);
        $this->assertSame([Privileged::docker()->limitMemory('shop', 768)], $this->execs);
    }

    public function test_a_stopped_container_only_gets_the_compose_file(): void
    {
        (new AccountMemory($this->dind(['memory_limit' => 512], running: false)))->apply();

        $this->assertSame('512M', Yaml::parse($this->written['/opt/users/'.$this->username.'/docker-compose.yml'])['services']['dind']['mem_limit']);
        $this->assertSame([], $this->execs);
    }

    public function test_an_enabled_s6_service_gets_a_fresh_env_dir_and_no_down_file(): void
    {
        $dir = '/opt/users/'.$this->username.'/services/cloudflared';

        (new S6ServiceManager($this->dind()))->configure('cloudflared', true, ['TUNNEL_TOKEN' => 'abc']);

        $this->assertSame([
            Privileged::files()->removeTree($dir.'/env'),
            Privileged::files()->makeDirectories($dir.'/env'),
            Privileged::files()->removeIfExists($dir.'/down'),
        ], $this->execs);
        $this->assertSame(["abc\n"], array_values($this->written));
        $this->assertSame([$dir.'/env/TUNNEL_TOKEN'], array_keys($this->written));
    }

    public function test_a_disabled_s6_service_gets_a_down_file(): void
    {
        $dir = '/opt/users/'.$this->username.'/services/cron';

        (new S6ServiceManager($this->dind()))->configure('cron', false);

        $this->assertSame(Privileged::files()->touch($dir.'/down'), end($this->execs));
        $this->assertSame([], $this->written);
    }

    public function test_applying_an_s6_service_runs_its_apply_script_in_the_account(): void
    {
        (new S6ServiceManager($this->dind()))->apply('cloudflared');

        $this->assertSame([S6ServiceManager::applyArgv('cloudflared')], array_map(LocalRoot::inAccount(...), $this->processes));
    }

    public function test_applying_a_supervisord_service_rereads_then_updates_it(): void
    {
        (new SupervisordServiceManager($this->dind()))->apply('cloudflared');

        $this->assertSame([
            ['supervisorctl', '-c', '/etc/supervisor/supervisord.conf', 'reread'],
            ['supervisorctl', '-c', '/etc/supervisor/supervisord.conf', 'update', 'cloudflared'],
        ], array_map(LocalRoot::inAccount(...), $this->processes));
    }

    public function test_a_tenant_firewall_that_cannot_be_applied_warns_and_the_recreate_goes_on(): void
    {
        $this->firewallFails = true;
        $logger = DeployLogger::start($this->username);

        (new OuterLifecycle($this->dind()))->recreate();

        $this->assertSame(
            array_fill(0, 2, 'The tenant network firewall could not be applied: iptables: No chain/target/match by that name.'),
            array_column(array_filter($logger->entries(), static fn (array $entry): bool => $entry['level'] === 'warn'), 'msg')
        );
        $this->assertSame([Privileged::compose('/opt/users/'.$this->username.'/docker-compose.yml')->up(true)], array_values(array_filter(
            $this->execs,
            static fn (array $argv): bool => $argv !== TenantNetwork::firewallArgv()
        )));
    }

    public function test_tearing_down_an_account_that_does_not_exist_is_refused(): void
    {
        $this->expectExceptionMessage("Project of the user '{$this->username}' doesn't exist");

        (new OuterLifecycle($this->dind()))->tearDown();
    }

    /** @param array<string, mixed> $details */
    private function dind(array $details = [], bool $running = false): Dind
    {
        $filesystem = $this->createStub(SystemFilesystem::class);
        $filesystem->method('fileGetContents')->willReturn(self::COMPOSE);
        $filesystem->method('filePutContents')->willReturnCallback(function (string $path, string $contents): void {
            $this->written[$path] = $contents;
        });
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $argv = array_values((array) $cmd);
            $this->execs[] = $argv;
            if ($this->firewallFails && $argv === TenantNetwork::firewallArgv()) {
                throw new \RuntimeException("iptables: No chain/target/match by that name.\n");
            }

            return '';
        });
        $system->method('runProcess')->willReturnCallback(function (string|array $cmd): Process {
            $this->processes[] = array_values((array) $cmd);

            return SuccessfulProcess::for($cmd);
        });

        $user = new User;
        $user->username = $this->username;
        $user->details = $details;

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('isRunning')->willReturn($running);
        $dind->method('exists')->willReturn(false);
        $dind->method('composeFilePath')->willReturn('/opt/users/'.$this->username.'/docker-compose.yml');
        $dind->method('projectDirPath')->willReturn('/opt/users/'.$this->username);
        $dind->method('homeDirPath')->willReturn('/home/'.$this->username);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }
}
