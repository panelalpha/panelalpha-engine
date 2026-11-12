<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\MissingLinuxUserException;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project\Dind;
use App\System\Project\Dind\RegistryLogin;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Logins that cannot be written leave the step anonymous instead of failing it. */
class RegistryLoginWriteFailureTest extends TestCase
{
    /** @var list<list<string>> */
    private array $commands = [];

    public function test_a_config_that_cannot_be_written_is_removed_and_the_step_runs_without_it(): void
    {
        Log::spy();
        $login = new RegistryLogin($this->project(chown: '1000:1000'));
        $seen = 'unset';

        $result = $login->during(function () use ($login, &$seen): string {
            $seen = $login->configDir();

            return 'ran';
        });

        $this->assertSame('ran', $result);
        $this->assertNull($seen);
        $this->assertCount(2, $this->commands);
        [$chmod, $rm] = $this->commands;
        $dir = (string) end($chmod);
        $this->assertStringStartsWith('/home/alice/.panelalpha-registry-', $dir);
        $this->assertSame(Privileged::accountFiles(1000, 1000)->changeMode('700', $dir), $chmod);
        $this->assertSame(Privileged::files()->removeTree($dir), $rm);
        Log::shouldHaveReceived('warning')->once()->with('Could not write registry logins for alice: disk full');
    }

    public function test_an_account_without_ids_writes_no_login(): void
    {
        Log::spy();
        $owners = [];
        $login = new RegistryLogin($this->project(chown: null, owners: $owners));
        $seen = 'unset';

        $login->during(function () use ($login, &$seen): void {
            $seen = $login->configDir();
        });

        $this->assertNull($seen);
        $this->assertSame([], $owners);
        Log::shouldHaveReceived('warning')->once();
    }

    /** @param list<?string> $owners */
    private function project(?string $chown, array &$owners = []): Dind
    {
        $user = $this->createStub(User::class);
        $user->method('getRegistryAuth')->willReturn('ghcr.io acme tok1');
        $user->method('getChownString')->willReturn($chown);
        if ($chown === null) {
            $user->method('linuxIds')->willThrowException(MissingLinuxUserException::for('alice'));
        } else {
            $user->method('linuxIds')->willReturn(array_map('intval', explode(':', $chown)));
        }

        $fs = $this->createStub(Filesystem::class);
        $fs->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents, ?string $owner = null, ?string $chmod = null) use (&$owners): void {
                $owners[] = $owner;
                throw new \RuntimeException('disk full');
            }
        );
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($fs);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->commands[] = (array) $cmd;

            return '';
        });

        $project = $this->createStub(Dind::class);
        $project->method('userModel')->willReturn($user);
        $project->method('username')->willReturn('alice');
        $project->method('homeDirPath')->willReturn('/home/alice');
        $project->method('system')->willReturn($system);
        $project->method('shell')->willReturn(new ShellOperations($project));

        return $project;
    }
}
