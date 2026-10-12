<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\CredentialFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A git command installs an askpass script carrying the project's token and a
 * deploy key into the account home, and removes them in a finally. A worker
 * that is SIGKILLed or a host reboot skips that, leaving both readable by the
 * tenant. Every command sweeps what an earlier one left before it starts.
 */
class ProjectGitCredentialSweepTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/pa-git-sweep-' . bin2hex(random_bytes(8));
        mkdir($this->home . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->home . '/project');
        @rmdir($this->home);
        parent::tearDown();
    }

    public function test_it_sweeps_the_askpass_and_deploy_key_a_lost_command_left(): void
    {
        $askpass = $this->home . '/' . CredentialFiles::ASKPASS_PREFIX . 'deadbeef';
        $key = $this->home . '/' . CredentialFiles::SSH_PREFIX . 'key-cafef00d';
        $knownHosts = $this->home . '/' . CredentialFiles::SSH_PREFIX . 'known-hosts-1234';
        file_put_contents($askpass, "#!/bin/sh\necho ghp_secret\n");
        file_put_contents($key, "-----BEGIN PRIVATE KEY-----\n");
        file_put_contents($knownHosts, "github.com ssh-ed25519 AAAA\n");
        foreach ([$askpass, $key, $knownHosts] as $file) {
            touch($file, time() - CredentialFiles::STALE_SECONDS - 60);
        }

        $system = new SweepRecordingSystem();
        (new SweepableGit($system, $this->home))->callExecute(['git', '-C', $this->home . '/project', 'status']);

        $this->assertFileDoesNotExist($askpass);
        $this->assertFileDoesNotExist($key);
        $this->assertFileDoesNotExist($knownHosts);
        $this->assertNotEmpty(
            array_filter($system->commands, fn (array $c): bool => array_slice($c, 0, 2) === ['sudo', 'rm']),
            'the sweep removes leftovers with sudo, as the cleanup does: the home is root-owned'
        );
    }

    /** A fresh credential file is a live command's, and stays. */
    public function test_it_leaves_a_running_commands_credentials_alone(): void
    {
        $askpass = $this->home . '/' . CredentialFiles::ASKPASS_PREFIX . 'live';
        file_put_contents($askpass, "#!/bin/sh\necho ghp_secret\n");

        $system = new SweepRecordingSystem();
        (new SweepableGit($system, $this->home))->callExecute(['git', '-C', $this->home . '/project', 'status']);

        $this->assertFileExists($askpass);
        @unlink($askpass);
    }

    /** An ordinary file, however it is named, is not a leftover credential. */
    public function test_it_leaves_ordinary_files_alone(): void
    {
        $docs = $this->home . '/.gitconfig';
        $project = $this->home . '/project/README.md';
        file_put_contents($docs, '[user]\n');
        file_put_contents($project, 'docs');

        $system = new SweepRecordingSystem();
        (new SweepableGit($system, $this->home))->callExecute(['git', '-C', $this->home . '/project', 'status']);

        $this->assertFileExists($docs);
        $this->assertFileExists($project);
    }

    /** A chown that fails after the install leaves neither the helper nor its local copy holding the token. */
    public function test_a_failed_chown_removes_the_installed_askpass_and_its_local_copy(): void
    {
        $system = new AskPassFailingSystem('chown');

        $this->executeWithToken($system);

        [$temp, $installed] = $system->installed();
        $this->assertFileDoesNotExist($temp, 'the local copy of the token was left behind');
        $this->assertFileDoesNotExist($installed, 'the installed helper was left in the account until the sweep');
    }

    public function test_a_failed_install_removes_the_local_copy(): void
    {
        $system = new AskPassFailingSystem('install');

        $this->executeWithToken($system);

        [$temp] = $system->installed();
        $this->assertFileDoesNotExist($temp, 'the local copy of the token was left behind');
    }

    private function executeWithToken(AskPassFailingSystem $system): void
    {
        try {
            (new SweepableGit($system, $this->home))->callExecute(['git', '-C', $this->home . '/project', 'status'], 'ghp_secret');
            $this->fail('the failed install should have failed the command');
        } catch (\App\System\Project\Git\Exception) {
        }
        $this->assertNotSame('', $system->installed()[0], 'the helper was never installed');
    }

    /** The classification itself, without a filesystem: a directory listing in. */
    public function test_leftovers_are_only_the_credential_prefixes(): void
    {
        $this->assertSame(
            [
                CredentialFiles::ASKPASS_PREFIX . 'abc',
                CredentialFiles::SSH_PREFIX . 'key-1',
            ],
            CredentialFiles::leftovers([
                '.',
                '..',
                'README.md',
                CredentialFiles::SSH_PREFIX . 'key-1',
                CredentialFiles::ASKPASS_PREFIX . 'abc',
            ])
        );
    }
}

/** @internal Exercises the real WorkTree::execute() with the host faked. */
final class SweepableGit extends ProjectGit
{
    public function __construct(
        private EngineSystem $fakeSystem,
        private string $fakeHome,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function callExecute(array $argv, ?string $token = null): string
    {
        return $this->execute($argv, $token);
    }

    protected function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';

        return $model;
    }

    protected function system(): EngineSystem
    {
        return $this->fakeSystem;
    }

    protected function homeDirPath(): string
    {
        return $this->fakeHome;
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        return '';
    }
}

/** @internal */
final class SweepRecordingSystem extends EngineSystem
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
        $this->commands[] = $argv;

        // `rm -f -- <path>` is the sweep's own command; carry it out for real so
        // the test sees what the host would have done.
        if (($argv[0] ?? '') === 'rm' || array_slice($argv, 0, 2) === ['sudo', 'rm']) {
            @unlink((string) end($argv));
        }

        return '';
    }
}

/** @internal Installs files for real, and fails the one step named. */
final class AskPassFailingSystem extends EngineSystem
{
    /** @var list<string> */
    private array $installArgv = [];

    public function __construct(private string $failingStep)
    {
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
        $step = $argv[1] ?? '';
        if ($step === 'install') {
            $this->installArgv = $argv;
        }
        if ($step === $this->failingStep) {
            throw new \RuntimeException("sudo {$step} failed");
        }
        match ($step) {
            'install' => copy($argv[4], $argv[5]),
            'rm' => @unlink((string) end($argv)),
            default => null,
        };

        return '';
    }

    /** @return array{0: string, 1: string} the local copy and where it was installed */
    public function installed(): array
    {
        return [(string) ($this->installArgv[4] ?? ''), (string) ($this->installArgv[5] ?? '')];
    }
}
