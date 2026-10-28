<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStartLine;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Tests\TestCase;

/**
 * A create or a rebuild logs its start before it clones, so it named no commit
 * in its deploy history. The line its clone ends on names the commit it checked out.
 */
class DindCloneCommitTest extends TestCase
{
    private const REPO = 'https://github.com/org/app.git';

    private const SHA = 'c0ffee0123456789abcdef0123456789abcdef01';

    private const CREATE = 'Deploy started (source: git, repo: ' . self::REPO . ')';

    private const REBUILD = 'Deploy started (source: rebuild)';

    private string $username;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->username = 'clc' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    public function test_a_create_names_the_commit_its_clone_checked_out(): void
    {
        $lines = $this->cloneUnderDeployLog($this->runner(), self::CREATE, null);

        $this->assertContains('Repository cloned (branch: main, commit: ' . self::SHA . ')', array_column($lines, 'msg'));
        $this->assertSame(['install', 'main', self::SHA], $this->history($lines));
    }

    public function test_a_rebuild_names_the_commit_its_reclone_checked_out(): void
    {
        $lines = $this->cloneUnderDeployLog($this->runner(), self::REBUILD, static function (): void {});

        $this->assertSame(['redeploy', 'main', self::SHA], $this->history($lines));
    }

    public function test_a_head_git_cannot_read_leaves_the_commit_out_and_the_clone_goes_on(): void
    {
        $runner = $this->runner();
        $runner->failIfContains = ['rev-parse HEAD'];

        $lines = $this->cloneUnderDeployLog($runner, self::CREATE, null);

        $this->assertContains('Repository cloned (branch: main)', array_column($lines, 'msg'));
        $this->assertSame(['install', 'main', null], $this->history($lines));
    }

    private function runner(): FakeGitRunner
    {
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'rev-parse HEAD' => self::SHA . "\n",
            'config --get remote.origin.url' => self::REPO . "\n",
            'branch --show-current' => "main\n",
        ];

        return $runner;
    }

    /**
     * @return list<array{ts: int, stage: ?string, level: string, msg: string}>
     */
    private function cloneUnderDeployLog(FakeGitRunner $runner, string $start, ?callable $beforeReplacing): array
    {
        $logger = DeployLogger::start($this->username);
        try {
            $logger->info($start);
            (new TestableGitRepository($this->dind(), $runner))->cloneConfiguredRepository($beforeReplacing);

            return $logger->read()['lines'];
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    /**
     * @param list<array{msg: string}> $lines
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function history(array $lines): array
    {
        $read = DeployStartLine::read($lines);

        return [$read['kind'], $read['branch'], $read['commit']];
    }

    private function dind(): Dind
    {
        $model = new ModelsUser();
        $model->username = $this->username;
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
            'git_repo' => self::REPO,
        ]);

        $system = new class () extends System {
            public function __construct()
            {
            }

            public function homesDirPath(): string
            {
                return '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }
        };

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
