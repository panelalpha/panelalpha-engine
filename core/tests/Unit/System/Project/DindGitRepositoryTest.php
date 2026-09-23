<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;

class DindGitRepositoryTest extends TestCase
{
    private string $tmpRoot;

    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/pa-dind-git-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
        mkdir($this->homeRoot.'/alice/project', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_status_without_repository_reports_not_connected_tree(): void
    {
        $project = $this->dindProject();
        $git = new TestableGitRepository($project, new FakeGitRunner);

        $status = $git->status();

        $this->assertFalse($status['connected']);
        $this->assertFalse($status['repository_exists']);
        $this->assertSame('project', $status['path_key']);
        $this->assertSame('site_git', $status['managed_by']);
    }

    public function test_deploy_managed_git_repo_uses_deploy_managed_by(): void
    {
        $model = $this->dindModel([
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
        ]);
        $project = $this->dind($model);
        $git = new TestableGitRepository($project, new FakeGitRunner);

        $this->assertTrue($git->isDeployManaged());
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "0\n",
            'rev-list --count HEAD..@{upstream}' => "0\n",
        ];
        $git = new TestableGitRepository($project, $runner);
        $status = $git->status();

        $this->assertSame('deploy', $status['managed_by']);
    }

    public function test_pull_ff_leaves_dirtiness_to_git(): void
    {
        $model = $this->dindModel();
        $model->putSiteGit('project', [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => null,
        ]);
        $project = $this->dind($model);
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'status --porcelain' => " M file\n?? uploads/\n",
        ];
        $git = new TestableGitRepository($project, $runner);

        $git->pull(GitRepository::STRATEGY_FF);

        $ran = array_map(static fn (array $cmd): string => implode(' ', $cmd), $runner->commands);
        $this->assertNotEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'fetch origin')));
        $this->assertNotEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'merge --ff-only origin/main')));
        $this->assertEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'reset --hard') || str_contains($c, 'clean -fd')));
    }

    public function test_pull_ff_refused_by_git_never_runs_the_destructive_restore(): void
    {
        $model = $this->dindModel();
        $model->putSiteGit('project', [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => null,
        ]);
        $project = $this->dind($model);
        $runner = new FakeGitRunner;
        $runner->stdout = ['rev-parse --is-inside-work-tree' => "true\n"];
        $runner->failIfContains = ['merge --ff-only'];
        $git = new TestableGitRepository($project, $runner);

        try {
            $git->pull(GitRepository::STRATEGY_FF);
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertStringContainsString('merge --ff-only', $e->getMessage());
        }

        $ran = array_map(static fn (array $cmd): string => implode(' ', $cmd), $runner->commands);
        $this->assertEmpty(array_filter($ran, static fn (string $c): bool => str_contains($c, 'reset --hard') || str_contains($c, 'clean -fd')));
    }

    public function test_disconnect_blocked_on_deploy_managed_checkout(): void
    {
        $model = $this->dindModel([
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
        ]);
        $project = $this->dind($model);
        $git = new TestableGitRepository($project, new FakeGitRunner);

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Git is managed by deploy.');
        $git->disconnect();
    }

    public function test_branches_parses_for_each_ref_output(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'for-each-ref' => implode("\n", [
                'refs/heads/main'."\x1f".'main'."\x1f".'origin/main'."\x1f".'*',
                'refs/remotes/origin/develop'."\x1f".'origin/develop'."\x1f".''."\x1f".'',
            ]),
        ];
        $git = new TestableGitRepository($project, $runner);

        $branches = $git->branches();

        $this->assertCount(2, $branches);
        $this->assertSame('main', $branches[0]['name']);
        $this->assertTrue($branches[0]['current']);
    }

    public function test_connect_on_empty_dir_persists_site_git(): void
    {
        $model = $this->dindModel();
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $git = new TestableGitRepository($this->dind($model), $runner);

        $status = $git->connect('https://github.com/org/repo.git', 'main', null);

        $this->assertTrue($status['connected']);
        $site = $model->getSiteGit('project');
        $this->assertNotNull($site);
        $this->assertSame('https://github.com/org/repo.git', $site['repo_url']);
        $this->assertSame('main', $site['branch']);
        $this->assertNull($site['token']);
    }

    public function test_commits_parses_log_output(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'log' => 'abc123'."\x1f".'abc'."\x1f".'First'."\x1f".'Ada'."\x1f".'2026-01-02T03:04:05Z',
        ];
        $git = new TestableGitRepository($project, $runner);

        $commits = $git->commits(10);

        $this->assertCount(1, $commits);
        $this->assertSame('abc123', $commits[0]['hash']);
        $this->assertSame('First', $commits[0]['subject']);
        $this->assertSame('Ada', $commits[0]['author']);
    }

    public function test_revert_hard_resets_and_cleans(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'rev-parse --verify HEAD' => "abc123\n",
            'reset --hard' => '',
            'clean -fd' => '',
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $git = new TestableGitRepository($project, $runner);

        $status = $git->revert();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'reset --hard HEAD'));
        $this->assertTrue($this->commandsContain($joined, 'clean -fd'));
        $this->assertFalse($status['dirty']);
    }

    /**
     * `Dind::noteAppConfigOverwritesTracked()` (ADR-0001 #06, D4) relies on
     * this to decide which paths get the "overwrites tracked file" deploy-log
     * line — it must report exactly what git tracks, nothing else.
     */
    public function test_tracked_among_returns_only_the_paths_git_tracks(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->stdout['ls-files'] = "a.txt\nb/c.txt\n";
        $git = new TestableGitRepository($project, $runner);

        $tracked = $git->trackedAmong(['a.txt', 'b/c.txt', 'untracked.txt']);

        $this->assertSame(['a.txt', 'b/c.txt'], $tracked);
    }

    /**
     * git C-quotes a tracked name that carries a control character even with
     * `core.quotepath=off` — the raw `"a\tb.txt"` line must come back as the
     * real name, not the quoted-and-escaped one.
     */
    public function test_tracked_among_unquotes_c_style_quoted_names(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        // Raw git output for a name containing a literal tab: the backslash
        // and the "t" are two separate characters, not an escape sequence.
        $runner->stdout['ls-files'] = "plain.txt\n".'"a\\tb.txt"'."\n";
        $git = new TestableGitRepository($project, $runner);

        $tracked = $git->trackedAmong(['plain.txt', "a\tb.txt"]);

        $this->assertSame(['plain.txt', "a\tb.txt"], $tracked);
    }

    public function test_tracked_among_returns_empty_without_calling_git_for_no_paths(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $git = new TestableGitRepository($project, $runner);

        $this->assertSame([], $git->trackedAmong([]));
        $this->assertSame([], $runner->commands);
    }

    public function test_has_repository_true_when_git_reports_a_work_tree(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->stdout['rev-parse --is-inside-work-tree'] = "true\n";
        $git = new TestableGitRepository($project, $runner);

        $this->assertTrue($git->hasRepository());
    }

    /**
     * Lenient by design: any failure (no `.git`, not a work tree, git
     * missing) reads as "no repository" rather than throwing.
     */
    public function test_has_repository_false_when_git_fails(): void
    {
        $project = $this->dindProject();
        $runner = new FakeGitRunner;
        $runner->failIfContains[] = 'rev-parse --is-inside-work-tree';
        $git = new TestableGitRepository($project, $runner);

        $this->assertFalse($git->hasRepository());
    }

    /**
     * @param  list<string>  $commands
     */
    private function commandsContain(array $commands, string $needle): bool
    {
        foreach ($commands as $cmd) {
            if (str_contains($cmd, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function dindProject(): Dind
    {
        return $this->dind($this->dindModel());
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
    }

    private function system(): System
    {
        return new class($this->tmpRoot, $this->homeRoot) extends System
        {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {}

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->homesRoot;
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
