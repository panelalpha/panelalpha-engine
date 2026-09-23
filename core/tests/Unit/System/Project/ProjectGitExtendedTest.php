<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\Exception as GitException;
use Tests\TestCase;

class ProjectGitExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

public function test_status_reports_dirty_tree_and_sanitized_remote_url(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
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
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->status();

        $this->assertFalse($status['connected']);
        $this->assertTrue($status['repository_exists']);
        $this->assertSame('https://github.com/org/repo.git', $status['remote_url']);
        $this->assertSame('main', $status['branch']);
        $this->assertTrue($status['dirty']);
        $this->assertSame('origin/main', $status['tracking']);
        $this->assertSame(2, $status['commits_ahead']);
        $this->assertSame(1, $status['commits_behind']);
    }

    public function test_status_fetch_false_does_not_call_fetch(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "0\n",
            'rev-list --count HEAD..@{upstream}' => "0\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $git->status(false);

        foreach ($runner->commands as $cmd) {
            $this->assertNotContains('fetch', $cmd);
        }
    }

    public function test_status_fetch_true_fetches_with_token_and_recomputes_counts(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'fetch origin' => '',
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => "origin/main\n",
            'rev-list --count @{upstream}..HEAD' => "3\n",
            'rev-list --count HEAD..@{upstream}' => "0\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->status(true);

        $this->assertTrue($status['connected']);
        $this->assertSame(3, $status['commits_ahead']);
        $this->assertSame(0, $status['commits_behind']);
        $fetchCommands = array_filter($runner->commands, fn (array $cmd) => in_array('fetch', $cmd, true));
        $this->assertCount(1, $fetchCommands);
    }

    public function test_status_fetch_true_requires_connection(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('Git is not connected.');
        $git->status(true);
    }

    public function test_branches_requires_repository(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $runner->failIfContains = ['rev-parse --is-inside-work-tree'];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->branches();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('No git repository at path', $e->getMessage());
        }
    }

    public function test_branches_lists_local_and_remote_tracking(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $sep = "\x1f";
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'for-each-ref' => implode("\n", [
                "refs/heads/main{$sep}main{$sep}origin/main{$sep}*",
                "refs/heads/feature|x{$sep}feature|x{$sep}{$sep}",
                "refs/remotes/origin/HEAD{$sep}origin/HEAD{$sep}{$sep}",
                "refs/remotes/origin/develop{$sep}origin/develop{$sep}{$sep}",
                "refs/remotes/origin/main{$sep}origin/main{$sep}{$sep}",
            ]) . "\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $branches = $git->branches();

        $this->assertCount(3, $branches);
        $this->assertSame('main', $branches[0]['name']);
        $this->assertTrue($branches[0]['current']);
        $this->assertSame('origin/main', $branches[0]['tracking']);
        $this->assertSame('feature|x', $branches[1]['name']);
        $this->assertFalse($branches[1]['current']);
        $this->assertNull($branches[1]['tracking']);
        $this->assertSame('develop', $branches[2]['name']);
        $this->assertFalse($branches[2]['current']);
        $this->assertSame('origin/develop', $branches[2]['tracking']);
        $this->assertSame(['main', 'feature|x', 'develop'], array_column($branches, 'name'));

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'for-each-ref'));
        $this->assertTrue($this->commandsContain($joined, 'refs/heads/'));
        $this->assertTrue($this->commandsContain($joined, 'refs/remotes/origin/'));
        // for-each-ref uses %xx, not git log pretty-format %xNN.
        $this->assertTrue($this->commandsContain(
            $joined,
            '--format=%(refname)%1f%(refname:short)%1f%(upstream:short)%1f%(HEAD)',
        ));
        $this->assertFalse($this->commandsContain($joined, '%x1f'));
    }

    public function test_configure_safe_directory_adds_once(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $absolute = '/home/alice/project';
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'config --global --get-all safe.directory' => '',
            'config --global --add safe.directory' => '',
        ];
        $git = $this->testable($model, 'project', $runner, $absolute);

        $git->configureSafeDirectory();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'config --global --add safe.directory'));
        $this->assertTrue($this->commandsContain($joined, $absolute));

        $runner2 = new FakeGitRunner();
        $runner2->stdout = [
            'config --global --get-all safe.directory' => $absolute . "\n",
        ];
        $git2 = $this->testable($model, 'project', $runner2, $absolute);
        $git2->configureSafeDirectory();

        $joined2 = array_map(fn (array $cmd) => implode(' ', $cmd), $runner2->commands);
        $this->assertFalse($this->commandsContain($joined2, 'config --global --add'));
    }

    public function test_revert_with_no_commits_on_head_throws(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
        ];
        $runner->failIfContains = ['rev-parse --verify HEAD'];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->revert();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Nothing to revert', $e->getMessage());
        }
    }

    public function test_revert_succeeds_when_upstream_not_configured(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'rev-parse --verify HEAD' => "abc123\n",
            'reset --hard HEAD' => '',
            'clean -fd' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'status --porcelain' => '',
        ];
        $runner->failIfContains = ['rev-parse --abbrev-ref @{upstream}'];
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->revert();

        $this->assertFalse($status['dirty']);
        $this->assertNull($status['tracking']);
    }

public function test_connect_when_origin_mismatches_throws_422(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/other/repo.git\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->connect('https://github.com/org/repo.git', 'main', null);
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Remote URL does not match the existing origin.', $e->getMessage());
        }
    }

    public function test_disconnect_removes_site_git_and_remote(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'status --porcelain' => '',
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->disconnect();

        $this->assertNull($model->getSiteGit('public_html'));
        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'remote remove'));
        $this->assertFalse($status['connected']);
    }

    public function test_connect_repair_reinits_from_stored_site_git(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
            'ls-remote --heads' => "abc\trefs/heads/main\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $git->connect('', '', null, true);

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $lsRemoteIndex = $this->commandIndexContaining($joined, 'ls-remote');
        $initIndex = $this->commandIndexContaining($joined, 'init');
        $this->assertNotFalse($lsRemoteIndex);
        $this->assertNotFalse($initIndex);
        $this->assertLessThan($initIndex, $lsRemoteIndex);
        $this->assertTrue($this->commandsContain($joined, 'remote add'));
        $this->assertStringContainsString('https://github.com/org/repo.git', implode("\n", $joined));
    }

    public function test_connect_repair_failed_ls_remote_does_not_init(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->failIfContains = ['ls-remote'];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->connect('', '', null, true);
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(400, $e->httpStatus);
        }

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'ls-remote'));
        $this->assertFalse($this->commandsContain($joined, 'init'));
        $this->assertFalse($this->commandsContain($joined, 'remote add'));
    }

    public function test_connect_repair_when_repo_exists_throws_422(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
        ];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->connect('', '', null, true);
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Local git repository already exists.', $e->getMessage());
        }
    }

    public function test_disconnect_when_not_connected_throws_422(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->disconnect();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Git is not connected.', $e->getMessage());
        }
    }

    public function test_update_credentials_updates_token_without_touching_origin(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'old-token',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = [
            'rev-parse --is-inside-work-tree' => "true\n",
            'status --porcelain' => '',
            'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
            'branch --show-current' => "main\n",
            'rev-parse --abbrev-ref @{upstream}' => '',
        ];
        $git = $this->testable($model, 'public_html', $runner);

        $git->updateCredentials('new-token');

        $site = $model->getSiteGit('public_html');
        $this->assertSame('new-token', $site['token']);
        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertFalse($this->commandsContain($joined, 'remote set-url'));
    }

    public function test_update_credentials_omit_leaves_token_unchanged(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'public_html', $runner);

        $git->updateCredentials(null, false);

        $site = $model->getSiteGit('public_html');
        $this->assertSame('pat-secret', $site['token']);
    }

    public function test_update_credentials_null_clears_token(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'public_html', $runner);

        $git->updateCredentials(null, true);

        $site = $model->getSiteGit('public_html');
        $this->assertNull($site['token']);
    }

public function test_pull_ff_refused_by_git_never_runs_the_destructive_restore(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => " M foo.txt\n",
        ]);
        $runner->failIfContains = ['merge --ff-only'];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->pull();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertStringContainsString('merge --ff-only', $e->getMessage());
        }

        // A refused merge --ff-only changes nothing, so restoring the backup
        // (reset --hard + clean -fd) could only destroy what git had spared.
        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertFalse($this->commandsContain($joined, 'reset --hard'));
        $this->assertFalse($this->commandsContain($joined, 'clean -fd'));
    }

    public function test_pull_skips_backup_when_head_is_unborn(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $runner->failIfContains = ['rev-parse --verify HEAD'];
        $git = $this->testable($model, 'public_html', $runner);

        $git->pull();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertFalse($this->commandsContain($joined, 'update-ref refs/panelalpha/backup'));
        $this->assertTrue($this->commandsContain($joined, 'merge --ff-only'));
    }

    public function test_pull_push_first_contains_merge(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => " M foo\n",
            'rev-list --count HEAD..@{upstream}' => "1\n",
        ]);
        $git = $this->testable($model, 'public_html', $runner);

        $git->pull(ProjectGit::STRATEGY_PUSH_FIRST);

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'merge'));
    }

    public function test_pull_push_first_restores_backup_when_merge_fails(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => " M foo\n",
            'rev-list --count HEAD..@{upstream}' => "1\n",
        ]);
        $runner->failIfContains = ['merge origin/main'];
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->pull(ProjectGit::STRATEGY_PUSH_FIRST);
            $this->fail('Expected GitException');
        } catch (GitException) {
            // Expected.
        }

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $mergeIndex = $this->commandIndexContaining($joined, 'merge origin/main');
        $restoreIndex = $this->commandIndexContaining($joined, 'reset --hard refs/panelalpha/backup');
        $this->assertNotFalse($mergeIndex);
        $this->assertNotFalse($restoreIndex);
        $this->assertLessThan($restoreIndex, $mergeIndex);
    }

public function test_change_branch_when_dirty_throws_without_reset(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => " M x\n",
        ]);
        $git = $this->testable($model, 'public_html', $runner);

        try {
            $git->changeBranch('develop');
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('Working tree is dirty.', $e->getMessage());
        }

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertFalse($this->commandsContain($joined, 'checkout -B'));
        $this->assertFalse($this->commandsContain($joined, 'reset --hard origin/develop'));
    }

    public function test_change_branch_checks_out_new_branch(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'public_html', $runner);

        $git->changeBranch('develop');

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'checkout -B develop origin/develop'));
        $this->assertFalse($this->commandsContain($joined, 'reset --hard'));
        $site = $model->getSiteGit('public_html');
        $this->assertSame('develop', $site['branch']);
    }

public function test_push_when_clean_and_ahead_pushes_without_commit(): void
    {
        $model = $this->connectedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => '',
            'rev-list --count @{upstream}..HEAD' => "2\n",
        ]);
        $git = $this->testable($model, 'public_html', $runner);

        $status = $git->push();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'push origin'));
        $this->assertFalse($this->commandsContain($joined, 'commit -m'));
        $this->assertArrayNotHasKey('nothing_to_push', $status);
    }

public function test_push_on_deploy_managed_path_pushes_when_ahead(): void
    {
        $model = $this->deployManagedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout([
            'status --porcelain' => '',
            'rev-list --count @{upstream}..HEAD' => "2\n",
        ]);
        $git = $this->testable($model, 'project', $runner);

        $status = $git->push();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'push origin'));
        $this->assertArrayNotHasKey('nothing_to_push', $status);
    }

    public function test_revert_on_deploy_managed_path_resets_hard(): void
    {
        $model = $this->deployManagedUser();
        $runner = new FakeGitRunner();
        $runner->stdout = $this->connectedRepoStdout();
        $git = $this->testable($model, 'project', $runner);

        $git->revert();

        $joined = array_map(fn (array $cmd) => implode(' ', $cmd), $runner->commands);
        $this->assertTrue($this->commandsContain($joined, 'reset --hard HEAD'));
        $this->assertTrue($this->commandsContain($joined, 'clean -fd'));
    }

public function test_clone_shallow_omits_branch_when_null(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $runner = new FakeGitRunner();
        $git = $this->testable($model, 'project', $runner);

        $git->clone('https://github.com/org/repo.git', null, null);

        $this->assertSame(
            [
                'git',
                '-c',
                'safe.directory=/home/alice/project',
                'clone',
                '--depth=1',
                'https://github.com/org/repo.git',
                '/home/alice/project',
            ],
            $runner->commands[0]
        );
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
        return new TestableProjectGit(new Project(new System(), $model), $path, $execute, $absolutePathOverride);
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
            'config --get user.name' => "Alice\n",
            'config --get user.email' => "alice@example.com\n",
            'add -A' => '',
            'commit -m' => '',
            'push origin' => '',
            'merge origin/main' => '',
        ], $overrides);
    }

    private function connectedUser(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->domain = 'example.com';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => 'pat-secret',
                ],
            ],
        ];

        return $model;
    }

    private function deployManagedUser(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = [
            'git_repo' => 'https://github.com/org/repo.git',
            'git_branch' => 'main',
            'git_token' => 'deploy-secret',
            'template' => 'dind',
        ];

        return $model;
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
}
