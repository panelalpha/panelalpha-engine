<?php

namespace Tests\Integration\System\Services;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\SftpAccount;
use App\System\Filesystem;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** A project whose Linux identity the host cannot answer for is left out of logins.conf, and the others still sync. */
class SftpRebuildSkipsUnansweredAccountTest extends TestCase
{
    use InMemoryDatabase;

    public function test_an_account_the_host_cannot_answer_for_is_logged_and_left_out(): void
    {
        $this->bootInMemoryDatabase();
        Log::spy();
        foreach (['alice', 'bob'] as $name) {
            $user = $this->makeUser($name);
            SftpAccount::create(['user_id' => $user->id, 'username' => "{$name}_sftp", 'auth_method' => 'password', 'password' => 'secret']);
        }
        $system = new class extends RecordingSystem {
            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public ?string $written = null;

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                if ($cmd === Privileged::onHost(Privileged::users()->uid('alice'))) {
                    return FakeProcess::failed('getent: database unavailable', 2);
                }

                return FakeProcess::ok("1001\n");
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private RecordingSystem $host)
                    {
                        parent::__construct($host);
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->written = $contents;
                    }
                };
            }
        };

        $system->sftp()->rebuildSftpAccounts();

        $this->assertSame("bob_sftp:secret:1001:1001:{$system->projectHomeDirPath('bob')}:\n", $system->written);
        $this->assertContains(Privileged::engineServices($system->composeFilePath())->sftpSyncLogins(), $system->commands);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Could not rebuild SFTP accounts for user alice'
                && str_contains($context['exception']->getMessage(), 'getent: database unavailable')
        );
    }
}
