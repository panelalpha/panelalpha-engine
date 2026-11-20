<?php

namespace Tests\Integration\System\Project;

use App\Exceptions\DockerErrorException;
use App\Models\User as ModelsUser;
use App\System as EngineSystem;
use App\System\Project\Git\CouldNotRun;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\WorkTree;
use Tests\TestCase;

/**
 * Git on a DinD project runs in the account's container, so a stopped container
 * or a refused exec never ran git and is not read as "no repository".
 */
class GitCouldNotRunTest extends TestCase
{
    private const NOT_RUNNING = 'service "dind" is not running';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_a_stopped_container_is_no_answer_about_the_repository(): void
    {
        $git = new ContainerWorkTree(quiet: new DockerErrorException(self::NOT_RUNNING));

        try {
            $git->status();
            $this->fail('A stopped container was read as a status.');
        } catch (CouldNotRun $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertSame("Git could not run: the project's container is not running (" . self::NOT_RUNNING . ')', $e->getMessage());
        }
    }

    public function test_a_pull_in_a_stopped_container_names_the_container_not_a_missing_repository(): void
    {
        $git = new ContainerWorkTree(quiet: new DockerErrorException(self::NOT_RUNNING));

        try {
            $git->pull();
            $this->fail('A pull ran in a stopped container.');
        } catch (CouldNotRun $e) {
            $this->assertStringNotContainsString('No git repository', $e->getMessage());
            $this->assertStringContainsString(self::NOT_RUNNING, $e->getMessage());
        }
    }

    /** A streamed command reports a stopped container as a plain failure, in docker's words rather than git's. */
    public function test_a_container_that_stops_during_a_pull_is_named_as_the_cause(): void
    {
        $git = new ContainerWorkTree(quiet: null, logged: new \RuntimeException(self::NOT_RUNNING . "\n"));

        try {
            $git->pull();
            $this->fail('A pull went on in a stopped container.');
        } catch (CouldNotRun $e) {
            $this->assertSame("Git could not run: the project's container is not running (" . self::NOT_RUNNING . ')', $e->getMessage());
        }
    }

    public function test_an_exec_docker_refused_is_no_answer_about_the_repository(): void
    {
        $refused = 'OCI runtime exec failed: exec failed: unable to start container process: unsafe procfs detected: unknown';
        $git = new ContainerWorkTree(quiet: new \RuntimeException($refused));

        try {
            $git->status();
            $this->fail('A refused exec was read as a status.');
        } catch (CouldNotRun $e) {
            $this->assertSame("Git could not run: docker would not run it in the project's container ({$refused})", $e->getMessage());
        }
    }

    /** hasRepository() promises no throw, and its callers read "no" as the safe answer. */
    public function test_has_repository_answers_no_without_a_throw_when_git_could_not_run(): void
    {
        $git = new ContainerWorkTree(quiet: new DockerErrorException(self::NOT_RUNNING));

        $this->assertFalse($git->hasRepository());
    }

    public function test_a_directory_git_says_is_no_repository_reads_as_none(): void
    {
        $git = new ContainerWorkTree(quiet: new \RuntimeException('fatal: not a git repository (or any parent up to mount point /home)'));

        $this->assertFalse($git->status()['repository_exists']);
        try {
            $git->pull();
            $this->fail('A pull ran without a repository.');
        } catch (GitException $e) {
            $this->assertNotInstanceOf(CouldNotRun::class, $e);
            $this->assertSame('No git repository at path', $e->getMessage());
        }
    }
}

/** @internal A connected Deploy-managed checkout whose container answers quiet and streamed commands as told. */
final class ContainerWorkTree extends WorkTree
{
    public function __construct(private ?\Throwable $quiet, private ?\Throwable $logged = null)
    {
        $this->absolutePath = sys_get_temp_dir() . '/pa-git-not-run-' . bin2hex(random_bytes(6)) . '/project';
        $this->pathKey = 'project';
    }

    protected function user(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
        ]);

        return $model;
    }

    protected function system(): EngineSystem
    {
        return new class extends EngineSystem {
            public function __construct()
            {
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }
        };
    }

    protected function homeDirPath(): string
    {
        return dirname($this->absolutePath);
    }

    protected function runCommand(array $cmd, int $timeout): string
    {
        if ($this->logged !== null) {
            throw $this->logged;
        }

        return '';
    }

    protected function runQuietCommand(array $cmd, int $timeout): string
    {
        if ($this->quiet !== null) {
            throw $this->quiet;
        }

        return in_array('--is-inside-work-tree', $cmd, true) ? "true\n" : '';
    }

    protected function runsOnHost(): bool
    {
        return false;
    }
}
