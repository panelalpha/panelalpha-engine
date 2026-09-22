<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Git\Exception as GitException;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use Tests\TestCase;
use Tests\Unit\System\Project\FakeGitRunner;

class ProjectGitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_default_path_for_dind_is_project(): void
    {
        $git = $this->project($this->dindModel())->git();

        $this->assertSame('/home/alice/project', $git->absolutePath());
        $this->assertSame('project', $git->pathKey());
    }

    public function test_default_path_for_php_hosting_is_public_html(): void
    {
        $git = $this->project($this->phpHostingModel())->git();

        $this->assertSame('/home/alice/public_html', $git->absolutePath());
        $this->assertSame('public_html', $git->pathKey());
    }

    public function test_explicit_path_is_resolved_under_home(): void
    {
        $git = $this->project($this->phpHostingModel())->git('sites/app');

        $this->assertSame('/home/alice/sites/app', $git->absolutePath());
        $this->assertSame('sites/app', $git->pathKey());
    }

    public function test_status_with_no_repo_and_not_connected(): void
    {
        $git = $this->testable($this->phpHostingModel(), 'public_html', new FakeGitRunner());

        $status = $git->status();

        $this->assertFalse($status['connected']);
        $this->assertFalse($status['repository_exists']);
        $this->assertSame('public_html', $status['path']);
        $this->assertSame('public_html', $status['path_key']);
        $this->assertSame('site_git', $status['managed_by']);
    }

    public function test_status_reports_dirty_tree_and_sanitized_remote_url(): void
    {
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'status --porcelain' => " M foo\n",
            'config --get remote.origin.url' => "https://user:x@github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "2\n",
            'rev-list --count HEAD..@{upstream}' => "1\n",
        ];
        $git = $this->testable($this->phpHostingModel(), 'public_html', $runner);

        $status = $git->status();

        $this->assertTrue($status['repository_exists']);
        $this->assertSame('https://github.com/org/repo.git', $status['remote_url']);
        $this->assertSame('main', $status['branch']);
        $this->assertTrue($status['dirty']);
        $this->assertSame(2, $status['commits_ahead']);
        $this->assertSame(1, $status['commits_behind']);
    }

    public function test_connect_on_empty_dir_inits_and_persists_site_git(): void
    {
        $model = $this->phpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->connect('https://github.com/org/repo.git', 'main', 'pat-secret');

        $joined = $this->joined($runner);
        $this->assertTrue($this->commandsContain($joined, 'init'));
        $this->assertTrue($this->commandsContain($joined, 'remote add'));
        $this->assertFalse($this->commandsContain($joined, 'clone'));
        $site = $model->getSiteGit('public_html');
        $this->assertNotNull($site);
        $this->assertSame('https://github.com/org/repo.git', $site['repo_url']);
        $this->assertSame('main', $site['branch']);
        $this->assertSame('pat-secret', $site['token']);
        $this->assertTrue($status['connected']);
    }

    /**
     * A bare `git init` has no remote history. Before this fix, connecting
     * over an existing (e.g. previously deployed) directory reported
     * connected:true with 0 branches and 0 commits until a separate
     * `git_pull(strategy:force)` -- whose `clean -fd` could then delete an
     * untracked file the directory already had. connect() must sync on its
     * own, without that destructive step.
     */
    public function test_connect_on_empty_dir_fetches_and_syncs_from_origin(): void
    {
        $model = $this->phpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-parse --verify --end-of-options origin/main' => "abc123\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $git->connect('https://github.com/org/repo.git', 'main', 'pat-secret');

        $joined = $this->joined($runner);
        $this->assertTrue($this->commandsContain($joined, 'fetch origin'));
        $this->assertTrue($this->commandsContain($joined, 'reset --hard origin/main'));
        $this->assertTrue($this->commandsContain($joined, 'branch --set-upstream-to=origin/main main'));
        $this->assertFalse($this->commandsContain($joined, 'clean -fd'), 'connect() must not delete untracked files the way pull(force) does');
    }

    /**
     * A bad repo/branch (or a network hiccup) on the initial sync must not
     * turn a working connect() into a failure -- it already created a valid,
     * if empty, local repository and persisted the metadata.
     */
    public function test_connect_on_empty_dir_survives_a_failed_initial_fetch(): void
    {
        $model = $this->phpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $runner->failIfContains = ['fetch origin'];
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->connect('https://github.com/org/repo.git', 'main', 'pat-secret');

        $this->assertTrue($status['connected']);
        $this->assertNotNull($model->getSiteGit('public_html'));
    }

    public function test_disconnect_blocked_on_deploy_managed_checkout(): void
    {
        $model = $this->dindModel();
        $git = $this->testable($model, 'project', new FakeGitRunner());

        $this->assertTrue($git->isDeployManaged());
        try {
            $git->disconnect();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Git is managed by deploy.', $e->getMessage());
        }
    }

    public function test_pull_ff_leaves_dirtiness_to_git(): void
    {
        $model = $this->connectedPhpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => " M foo.txt\n?? uploads/\n",
        ]);
        $git = $this->testable($model, 'public_html', $runner);

        $git->pull();

        $joined = $this->joined($runner);
        $this->assertTrue($this->commandsContain($joined, 'fetch origin'));
        $this->assertTrue($this->commandsContain($joined, 'merge --ff-only origin/main'));
        $this->assertFalse($this->commandsContain($joined, 'reset --hard'));
        $this->assertFalse($this->commandsContain($joined, 'clean -fd'));
    }

    public function test_pull_default_ff_fetches_then_ff_only_merge(): void
    {
        $model = $this->connectedPhpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'public_html', $runner);

        $git->pull();

        $joined = $this->joined($runner);
        $fetchIndex = $this->commandIndexContaining($joined, 'fetch');
        $mergeIndex = $this->commandIndexContaining($joined, 'merge --ff-only');
        $this->assertNotFalse($fetchIndex);
        $this->assertNotFalse($mergeIndex);
        $this->assertLessThan($mergeIndex, $fetchIndex);
    }

    public function test_change_branch_on_deploy_managed_path_mirrors_git_branch(): void
    {
        $model = $this->dindModel();
        $model->putSiteGit('project', [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => 'deploy-secret',
        ]);
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'project', $runner);

        $git->changeBranch('develop');

        $joined = $this->joined($runner);
        $this->assertTrue($this->commandsContain($joined, 'checkout -B develop origin/develop'));
        $this->assertSame('develop', $model->getGitBranch());
        $this->assertSame('develop', $model->getSiteGit('project')['branch']);
    }

    public function test_clone_shallow_builds_argv_without_dash_c(): void
    {
        $runner = new FakeGitRunner();
        $git = $this->testable($this->dindModel(), 'project', $runner);

        $git->clone('https://github.com/org/repo.git', 'main', 'pat-secret');

        $this->assertSame(
            [
                'git',
                '-c',
                'safe.directory=/home/alice/project',
                'clone',
                '--depth=1',
                '--branch',
                'main',
                'https://github.com/org/repo.git',
                '/home/alice/project',
            ],
            $runner->commands[0]
        );
        $flat = implode(' ', $runner->commands[0]);
        $this->assertStringNotContainsString(' -C ', ' ' . $flat . ' ');
        $this->assertStringNotContainsString('pat-secret', $flat);
    }

    public function test_init_submodules_fetches_when_gitmodules_present(): void
    {
        $dir = sys_get_temp_dir() . '/pa-project-git-sub-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/.gitmodules',
            "[submodule \"3rdparty\"]\n\tpath = 3rdparty\n\turl = https://github.com/nextcloud/3rdparty.git\n"
        );

        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'submodule update' => '',
        ];
        $git = $this->testable($this->dindModel(), 'project', $runner, $dir);

        $this->assertTrue($git->initSubmodules());

        $submodule = null;
        foreach ($runner->commands as $cmd) {
            if (in_array('submodule', $cmd, true)) {
                $submodule = $cmd;
            }
        }
        $this->assertNotNull($submodule);
        $this->assertContains('--depth=1', $submodule);
        $this->assertContains('--recursive', $submodule);

        unlink($dir . '/.gitmodules');
        rmdir($dir);
    }

    public function test_adopt_checkout_persists_site_git_without_init(): void
    {
        $model = $this->dindModel();
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --verify --end-of-options origin/main' => "abc123\n",
            'branch --set-upstream-to=origin/main main' => '',
            'status --porcelain' => '',
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "0\n",
            'rev-list --count HEAD..@{upstream}' => "0\n",
        ];
        $git = $this->testable($model, 'project', $runner);

        $git->adoptCheckout('https://github.com/org/repo.git', 'main', 'deploy-secret');

        $joined = $this->joined($runner);
        $this->assertFalse($this->commandsContain($joined, 'init'));
        $this->assertFalse($this->commandsContain($joined, 'clone'));
        $site = $model->getSiteGit('project');
        $this->assertNotNull($site);
        $this->assertSame('https://github.com/org/repo.git', $site['repo_url']);
        $this->assertSame('deploy-secret', $model->getGitToken());
    }

    public function test_init_submodules_skips_when_no_gitmodules(): void
    {
        $dir = sys_get_temp_dir() . '/pa-project-git-sub-empty-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
        ];
        $git = $this->testable($this->dindModel(), 'project', $runner, $dir);

        $this->assertFalse($git->initSubmodules());

        foreach ($runner->commands as $cmd) {
            $this->assertNotContains('submodule', $cmd);
        }

        rmdir($dir);
    }

    public function test_push_when_behind_throws_without_pushing(): void
    {
        $model = $this->connectedPhpHostingModel();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'rev-list --count HEAD..@{upstream}' => "1\n",
        ]);
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->push();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame('Pull first.', $e->getMessage());
        }

        $this->assertFalse($this->commandsContain($this->joined($runner), 'push origin'));
    }

    /**
     * @param (callable(list<string>, ?string, int): string)|FakeGitRunner $execute
     */
    private function testable(
        ModelsUser $model,
        string $path,
        $execute,
        ?string $absolutePathOverride = null,
    ): TestableProjectGit {
        return new TestableProjectGit($this->project($model), $path, $execute, $absolutePathOverride);
    }

    private function project(ModelsUser $model): Project
    {
        return new Project(new System(), $model);
    }

    private function phpHostingModel(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';

        return $model;
    }

    private function dindModel(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
            'git_token' => 'deploy-secret',
        ]);

        return $model;
    }

    private function connectedPhpHostingModel(): ModelsUser
    {
        $model = $this->phpHostingModel();
        $model->domain = 'example.com';
        $model->putSiteGit('public_html', [
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => 'pat-secret',
        ]);

        return $model;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function connectedRepoStdout(array $overrides = []): array
    {
        return array_merge([
            'rev-parse --is-inside-work-tree' => "true\n",
            'rev-parse --verify HEAD' => "abc123\n",
            'rev-parse --verify --end-of-options origin/main' => "abc123\n",
            'rev-parse --verify --end-of-options origin/develop' => "abc123\n",
            'fetch origin' => '',
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "0\n",
            'rev-list --count HEAD..@{upstream}' => "0\n",
            'update-ref refs/panelalpha/backup' => '',
            'branch --set-upstream-to=origin/main main' => '',
            'branch --set-upstream-to=origin/develop develop' => '',
            'reset --hard origin/main' => '',
            'merge --ff-only' => '',
            'checkout -B' => '',
            'clean -fd' => '',
        ], $overrides);
    }

    /**
     * @return list<string>
     */
    private function joined(FakeGitRunner $runner): array
    {
        return array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
    }

    /**
     * @param list<string> $commands
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

    /**
     * @param list<string> $commands
     */
    private function commandIndexContaining(array $commands, string $needle): int|false
    {
        foreach ($commands as $index => $cmd) {
            if (str_contains($cmd, $needle)) {
                return $index;
            }
        }

        return false;
    }
}
