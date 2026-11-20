<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountTeardown;
use App\System\Project\Dind\Inner\StorageReclaim;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Services\ServiceManager;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Deleting a DinD account wipes its inner Docker store through the account's
 * dockerd. A deploy log left cancelled must not stop that, and an account
 * whose container is not running has no dockerd to wipe through: its store
 * goes with the home, and nothing is worth a warning.
 */
class DataRootWipeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array{0: string, 1: list<string>}> */
    private array $asked = [];

    /** A delete that follows a cancelled deploy: latest.json still says cancelled. */
    public function test_a_cancelled_deploy_log_does_not_stop_the_wipe(): void
    {
        $username = 'wipe' . bin2hex(random_bytes(3));
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        DeployLogger::start($username)->stage(DeployLogger::STAGE_PREPARING);
        DeployLogger::requestCancel($username);

        $ran = [];
        $system = Mockery::mock(System::class);
        $system->shouldReceive('runProcess')->andReturnUsing(static function (array $cmd) use (&$ran): Process {
            $ran[] = end($cmd);

            return new Process(['true']);
        });
        $system->shouldReceive('exec')->andReturnUsing(static function (array $cmd) use (&$ran): string {
            $ran[] = end($cmd);

            return '';
        });

        $services = Mockery::mock(ServiceManager::class);
        $services->shouldReceive('stopArgv')->with('docker')->andReturn(['sh', '-c', 'stop dockerd']);
        $engine = Mockery::mock(ContainerEngine::class);
        $engine->shouldReceive('storage')->andReturn(new DindAccountStorage());

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('username')->andReturn($username);
        $dind->shouldReceive('composeFilePath')->andReturn("/opt/panelalpha/shared-hosting/users/{$username}/docker-compose.yml");
        $dind->shouldReceive('homeDirPath')->andReturn("/home/{$username}");
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('services')->andReturn($services);
        $dind->shouldReceive('engine')->andReturn($engine);
        $shell = new ShellOperations($dind);
        $dind->shouldReceive('shell')->andReturn($shell);
        $inner = Mockery::mock(InnerDocker::class);
        $inner->shouldReceive('dind')->andReturn($dind);

        (new StorageReclaim($inner))->wipeDataRoot();

        $this->assertSame(['stop dockerd', (new DindAccountStorage())->fullWipeScript()], $ran);
    }

    /** Stopped with `docker stop`: compose names no running service. */
    public function test_a_stopped_account_is_not_wiped_and_nothing_is_logged(): void
    {
        Log::spy();
        $dind = $this->teardownOf(outerStackExists: true, running: false);
        $dind->shouldNotReceive('innerDocker');

        (new AccountTeardown($dind))->delete();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_an_account_whose_outer_stack_is_gone_is_not_wiped(): void
    {
        Log::spy();
        $dind = $this->teardownOf(outerStackExists: false, running: false);
        $dind->shouldNotReceive('innerDocker');

        (new AccountTeardown($dind))->delete();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_running_account_is_still_wiped(): void
    {
        $dind = $this->teardownOf(outerStackExists: true, running: true);
        $inner = Mockery::mock(InnerDocker::class);
        $inner->shouldReceive('wipeDataRoot')->once();
        $dind->shouldReceive('innerDocker')->andReturn($inner);

        (new AccountTeardown($dind))->delete();
    }

    /** The build cache goes with the aborted deploy, then everything, volumes included: they are the account's. */
    public function test_deleting_an_account_prunes_its_daemon_down_to_the_volumes(): void
    {
        $dind = $this->teardownOf(outerStackExists: true, running: true);
        $inner = Mockery::mock(InnerDocker::class);
        $inner->shouldReceive('wipeDataRoot');
        $dind->shouldReceive('innerDocker')->andReturn($inner);

        (new AccountTeardown($dind))->delete();

        $prunes = array_values(array_filter($this->asked, static fn (array $asked): bool => str_contains($asked[0], 'prune')));
        $this->assertSame([
            ['account-docker.pruneBuildCache', ['alice']],
            ['account-docker.pruneSystem', ['alice', 'volumes']],
        ], $prunes);
    }

    /** An aborted deploy takes only the build cache: the images and volumes may be the live version's. */
    public function test_aborting_a_deploy_prunes_only_the_build_cache(): void
    {
        $dind = $this->teardownOf(outerStackExists: true, running: true);

        (new AccountTeardown($dind))->abortRunningDeploy(stopInnerDocker: false);

        $this->assertSame([['account-docker.pruneBuildCache', ['alice']]], $this->asked);
    }

    private function teardownOf(bool $outerStackExists, bool $running): Dind&MockInterface
    {
        $filesystem = Mockery::mock(SystemFilesystem::class);
        $filesystem->shouldReceive('fileExists')->andReturn(false);
        $system = Mockery::mock(System::class);
        $system->shouldReceive('homesDirPath')->andReturn('/home');
        $system->shouldReceive('filesystem')->andReturn($filesystem);
        $system->shouldReceive('exec')->andReturnUsing(function (string|array $cmd) use ($running): string {
            $action = (string) LocalRoot::action((array) $cmd);
            if ($action === 'compose.runningServices') {
                return $running ? "dind\n" : '';
            }
            if (str_starts_with($action, 'account-docker.')) {
                $this->asked[] = [$action, LocalRoot::values((array) $cmd)];
                if (! $running) {
                    throw new DockerErrorException('container alice is not running');
                }
            }

            return '';
        });
        $system->shouldReceive('runProcess')->andReturn(new Process(['true']));

        $engine = Mockery::mock(ContainerEngine::class);
        $engine->shouldReceive('storage')->andReturn(new DindAccountStorage());
        $owner = Mockery::mock(ModelsUser::class);
        $owner->shouldReceive('getChownString')->andReturn('1000:1000');

        $dir = sys_get_temp_dir() . '/teardown-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $compose = "{$dir}/docker-compose.yml";
        if ($outerStackExists) {
            touch($compose);
        }
        $this->beforeApplicationDestroyed(static function () use ($dir, $compose): void {
            @unlink($compose);
            @rmdir($dir);
        });

        // exists() and isRunning() are the real ones.
        $dind = Mockery::mock(Dind::class)->makePartial();
        $dind->shouldReceive('username')->andReturn('alice');
        $dind->shouldReceive('composeFilePath')->andReturn($compose);
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('engine')->andReturn($engine);
        $dind->shouldReceive('userModel')->andReturn($owner);
        $dind->shouldReceive('userAppComposeFilePath')->andReturn('/home/alice/project/docker-compose.panelalpha.yml');
        $dind->shouldReceive('userAppExistingComposeFilePath')->andReturnNull();
        $dind->shouldReceive('userAppComposeCommand')->andReturn(['docker', 'compose', 'down']);
        $dind->shouldReceive('engineAccount')->andReturn(new EngineAccount('alice', '/home/alice', '1000:1000'));
        $shell = new ShellOperations($dind);
        $dind->shouldReceive('shell')->andReturn($shell);

        return $dind;
    }
}
