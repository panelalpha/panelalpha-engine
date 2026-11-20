<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\Git\DeployKey;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\WorkTree;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The real WorkTree::execute() and its credential and work-tree steps, against a host that refuses what it is told to.
 */
class GitWorkTreeExecuteFailuresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_a_repair_whose_init_fails_removes_the_git_directory_it_made(): void
    {
        $model = $this->connectedUser();
        $model->setDetails(['UID' => 1001, 'GID' => 1002]);
        $system = new RefusingHostSystem(['files.removeTree']);
        $git = new ScriptedWorkTree($model, $system, function (array $cmd): string {
            $joined = implode(' ', $cmd);
            if (str_contains($joined, 'rev-parse --is-inside-work-tree')) {
                throw new \RuntimeException('fatal: not a git repository (or any of the parent directories): .git');
            }
            if (str_contains($joined, 'ls-remote')) {
                return "abc123\trefs/heads/main\n";
            }
            if (str_ends_with($joined, ' init')) {
                throw new \RuntimeException('fatal: cannot mkdir .git: Permission denied');
            }

            return '';
        });

        try {
            $git->connect('', '', null, true);
            $this->fail('A failed init was reported as a repair.');
        } catch (GitException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertStringContainsString('cannot mkdir .git', $e->getMessage());
        }

        $tree = $git->absolutePath();
        $this->assertContains(['files.makeDirectories', $tree], $system->asked);
        $this->assertContains(['files.changeOwner', '1001:1002', $tree], $system->asked);
        $this->assertContains(['files.removeTree', $tree . '/.git'], $system->asked);
    }

    public function test_an_argument_git_cannot_take_is_refused_as_a_422(): void
    {
        $ran = [];
        $git = new ScriptedWorkTree($this->connectedUser(), new RefusingHostSystem(), function (array $cmd) use (&$ran): string {
            $ran[] = $cmd;
            throw new \InvalidArgumentException('Path must stay inside the checkout.');
        });

        try {
            $git->trackedAmong(['../outside']);
            $this->fail('The listing ran.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Path must stay inside the checkout.', $e->getMessage());
        }
        $this->assertSame(['--literal-pathspecs', 'ls-files', '--', '../outside'], array_slice($ran[0], -4));
    }

    public function test_a_host_fetch_goes_out_unpinned_when_origin_cannot_be_read(): void
    {
        $ran = [];
        $git = new ScriptedWorkTree($this->connectedUser(), new RefusingHostSystem(), function (array $cmd) use (&$ran): string {
            $joined = implode(' ', $cmd);
            $ran[] = $joined;
            if (str_contains($joined, 'rev-parse --is-inside-work-tree')) {
                return "true\n";
            }
            if (str_contains($joined, 'remote get-url')) {
                throw new \RuntimeException('error: No such remote \'origin\'');
            }

            return str_contains($joined, '--is-shallow-repository') ? "false\n" : '';
        }, onHost: true);

        $git->fetchFullHistory(null);

        $fetches = array_values(array_filter($ran, fn (string $c): bool => str_contains($c, 'fetch --tags origin')));
        $this->assertCount(1, $fetches);
        $this->assertStringNotContainsString('curloptResolve', $fetches[0]);
        $this->assertNotEmpty(array_filter($ran, fn (string $c): bool => str_contains($c, 'remote get-url --all origin')));
    }

    public function test_a_deploy_key_that_cannot_be_installed_is_taken_away_again(): void
    {
        Log::spy();
        $model = $this->connectedUser();
        $model->setDetails([DeployKey::DETAIL => ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nk\n"]]);
        $system = new RefusingHostSystem(['files.removeIfExists'], 'known-hosts');
        $ran = [];
        $git = new ScriptedWorkTree($model, $system, function (array $cmd) use (&$ran): string {
            $joined = implode(' ', $cmd);
            $ran[] = $joined;

            return match (true) {
                str_contains($joined, 'rev-parse --is-inside-work-tree') => "true\n",
                str_contains($joined, '--is-shallow-repository') => "true\n",
                default => '',
            };
        });

        try {
            $git->fetchFullHistory(null);
            $this->fail('The fetch ran without its known hosts.');
        } catch (GitException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertStringContainsString('refused to install', $e->getMessage());
        }

        $this->assertEmpty(array_filter($ran, fn (string $c): bool => str_contains($c, 'fetch --unshallow')));
        $removed = array_values(array_filter($system->asked, fn (array $a): bool => $a[0] === 'files.removeIfExists'));
        $this->assertCount(1, $removed);
        $this->assertStringContainsString('.panelalpha-git-ssh-key-', $removed[0][1]);
        Log::shouldHaveReceived('warning')->with('Could not remove a Git credential file', \Mockery::on(
            fn (array $context): bool => $context['path'] === $removed[0][1],
        ));
    }

    private function connectedUser(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = ['site_git' => ['public_html' => [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
        ]]];

        return $model;
    }
}

/** @internal A checkout under a home that does not exist, whose git answers as $run says. */
final class ScriptedWorkTree extends WorkTree
{
    /** @param \Closure(list<string>): string $run */
    public function __construct(
        private ModelsUser $model,
        private EngineSystem $host,
        private \Closure $run,
        private bool $onHost = false,
    ) {
        $this->absolutePath = sys_get_temp_dir() . '/pa-git-execute-' . bin2hex(random_bytes(6)) . '/public_html';
        $this->pathKey = 'public_html';
    }

    protected function user(): ModelsUser
    {
        return $this->model;
    }

    protected function system(): EngineSystem
    {
        return $this->host;
    }

    protected function homeDirPath(): string
    {
        return dirname($this->absolutePath);
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        return ($this->run)($cmd);
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        return ($this->run)($cmd);
    }

    protected function runsOnHost(): bool
    {
        return $this->onHost;
    }
}

/** @internal Records each broker action with its values, and refuses the ones named, or an install to a path containing $refuseInstallTo. */
final class RefusingHostSystem extends EngineSystem
{
    /** @var list<list<string>> */
    public array $asked = [];

    /** @param list<string> $refused */
    public function __construct(private array $refused = [], private ?string $refuseInstallTo = null)
    {
    }

    /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
    public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
    {
        return FakeProcess::failed('no process is started in this test');
    }

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
        $action = LocalRoot::action($argv);
        $values = $action !== null ? LocalRoot::values($argv) : [];
        $this->asked[] = [(string) $action, ...$values];
        if (in_array($action, $this->refused, true)) {
            throw new \RuntimeException("{$action} refused");
        }
        if ($action === 'files.install' && $this->refuseInstallTo !== null && str_contains((string) end($values), $this->refuseInstallTo)) {
            throw new \RuntimeException('install: refused to install ' . end($values));
        }

        return '';
    }
}
