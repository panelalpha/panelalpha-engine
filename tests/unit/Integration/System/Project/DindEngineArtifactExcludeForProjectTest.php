<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\EngineArtifactExclude;
use App\System\Project\Dind\SourceAccess;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeGitRunner;
use Tests\Support\FakeProcess;
use Tests\Support\TestableGitRepository;
use Tests\TestCase;

/**
 * The exclude block as a deploy writes it: git asked in the account's container, the file written through the
 * host's filesystem as the account, and a write that fails logged without failing the deploy.
 */
class DindEngineArtifactExcludeForProjectTest extends TestCase
{
    private string $root;

    /** @var list<array{0: string, 1: string, 2: ?string, 3: ?string}> path, contents, owner, mode */
    private array $written = [];

    private ?\Throwable $writeFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir() . '/pa-exclude-project-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/project/.git/info', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_block_is_written_as_the_account_into_the_checkouts_exclude_file(): void
    {
        EngineArtifactExclude::forProject($this->dind())->write();

        $this->assertCount(1, $this->written);
        [$path, $contents, $owner, $mode] = $this->written[0];
        $this->assertSame($this->root . '/home/alice/project/.git/info/exclude', $path);
        $this->assertContains('/panelalpha.Dockerfile', explode("\n", $contents));
        $this->assertSame('1001:1001', $owner);
        $this->assertSame('644', $mode);
    }

    public function test_a_block_that_cannot_be_written_is_logged_and_the_deploy_goes_on(): void
    {
        Log::spy();
        $this->writeFailure = new \RuntimeException('Read-only file system');

        EngineArtifactExclude::forProject($this->dind())->write();

        Log::shouldHaveReceived('warning')->with('Could not update the Engine Artifact exclude block', [
            'checkout' => $this->root . '/home/alice/project',
            'error' => 'Read-only file system',
        ]);
    }

    public function test_a_change_to_a_checkout_the_deploy_does_not_manage_rebuilds_nothing(): void
    {
        $dind = $this->dind(['git_repo' => '', 'site_git' => ['public_html' => ['repo_url' => 'https://github.com/org/site.git', 'branch' => 'main']]]);
        $git = new TestableGitRepository($dind, new FakeGitRunner(), 'public_html');

        (new SourceAccess($dind))->afterGitMutation($git);

        $this->assertFalse($git->isDeployManaged());
        $this->assertNull(DeployLogger::readLatestFor('alice'));
    }

    /** @param array<string, mixed> $details */
    private function dind(array $details = []): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'git_repo' => 'https://github.com/org/panel.git', 'UID' => 1001, 'GID' => 1001, ...$details]);
        $test = $this;
        $system = new class ($this->root, $test) extends System {
            public function __construct(private string $root, private DindEngineArtifactExcludeForProjectTest $test)
            {
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, "'--git-path' 'info/exclude'")) {
                    return "\n.git/info/exclude\n";
                }

                return str_contains($line, "'--is-inside-work-tree'") ? "true\n" : '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->test) extends Filesystem {
                    public function __construct(System $system, private DindEngineArtifactExcludeForProjectTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->test->recordWrite($path, $contents, $chown, $chmod);
                    }
                };
            }
        };
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** @internal what the host's filesystem was asked to write */
    public function recordWrite(string $path, string $contents, ?string $chown, ?string $chmod): void
    {
        if ($this->writeFailure !== null) {
            throw $this->writeFailure;
        }
        $this->written[] = [$path, $contents, $chown, $chmod];
    }
}
