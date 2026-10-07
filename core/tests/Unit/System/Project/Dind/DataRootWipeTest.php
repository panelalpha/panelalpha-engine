<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Dind\DindAccountStorage;
use App\Lib\Deploy\Engine\ContainerEngine;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Engine\ImageStore;
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

    private function teardownOf(bool $outerStackExists, bool $running): Dind&MockInterface
    {
        $filesystem = Mockery::mock(SystemFilesystem::class);
        $filesystem->shouldReceive('fileExists')->andReturn(false);
        $system = Mockery::mock(System::class);
        $system->shouldReceive('homesDirPath')->andReturn('/home');
        $system->shouldReceive('filesystem')->andReturn($filesystem);
        // isRunning() asks compose which of the account's services run.
        $system->shouldReceive('exec')->andReturnUsing(
            static fn (string|array $cmd): string => is_string($cmd) && str_contains($cmd, 'ps --services --filter status=running')
                ? ($running ? "dind\n" : '')
                : ''
        );
        $system->shouldReceive('runProcess')->andReturn(new Process(['true']));

        $images = Mockery::mock(ImageStore::class);
        $images->shouldReceive('listImagesArgv')->andReturn(['docker', 'images']);
        $engine = Mockery::mock(ContainerEngine::class);
        $engine->shouldReceive('storage')->andReturn(new DindAccountStorage());
        $engine->shouldReceive('images')->andReturn($images);
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
        $dind->shouldReceive('engineAccount')->andReturn(new EngineAccount('alice', '/home/alice', '1000:1000', $compose));
        $shell = new ShellOperations($dind);
        $dind->shouldReceive('shell')->andReturn($shell);

        return $dind;
    }
}
