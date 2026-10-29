<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git as ProjectGit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The token helper a work tree installs answers only its checkout's remote
 * host, also for a command that does not name a remote itself.
 */
class GitAskPassHostTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir() . '/pa-git-askpass-host-' . bin2hex(random_bytes(8));
        mkdir($this->home . '/project', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->home . '/project');
        @rmdir($this->home);
        parent::tearDown();
    }

    public function test_a_local_commands_helper_answers_only_the_checkouts_remote_host(): void
    {
        $system = new AskPassCapturingSystem();

        (new AskPassHostGit($system, $this->home))->callExecute(['git', '-C', $this->home . '/project', 'reset', '--hard'], 'ghp_secret');

        $this->assertSame('ghp_secret', $this->answer($system->script, "Password for 'https://git@github.com':"));
        $this->assertNull($this->answer($system->script, "Password for 'https://evil.example':"));
    }

    private function answer(string $script, string $prompt): ?string
    {
        $path = $this->home . '/helper';
        file_put_contents($path, $script);
        chmod($path, 0700);
        $process = new Process([$path, $prompt]);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}

/** @internal Exercises the real WorkTree::execute() for a checkout of github.com. */
final class AskPassHostGit extends ProjectGit
{
    public function __construct(
        private EngineSystem $fakeSystem,
        private string $fakeHome,
    ) {
        $this->pathKey = 'project';
    }

    /** @param list<string> $argv */
    public function callExecute(array $argv, ?string $token = null): string
    {
        return $this->execute($argv, $token);
    }

    protected function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['git_repo' => 'https://github.com/acme/app.git']);

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

/** @internal Keeps the helper an install would have put in place. */
final class AskPassCapturingSystem extends EngineSystem
{
    public string $script = '';

    public function __construct()
    {
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
        if (($argv[1] ?? '') === 'install' && str_contains((string) ($argv[5] ?? ''), 'askpass')) {
            $this->script = (string) file_get_contents($argv[4]);
        }

        return '';
    }
}
