<?php

namespace Tests\Integration\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Tests\Support\TestableProjectGit;
use Tests\TestCase;

/**
 * The work tree's refusals and best-effort steps, each driven through a public call with git answering as scripted.
 */
class ProjectGitWorkTreeEdgesTest extends TestCase
{
    private const SEP = "\x1f";

    /** @var list<string> */
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->commands = [];
    }

    public function test_an_untracked_path_never_differs_from_head(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', [['ls-files', '']]);

        $this->assertFalse($git->fileDiffersFromHead('config.php'));
        $this->assertFalse($this->ran('diff --name-only HEAD'));
    }

    public function test_a_path_head_does_not_have_reads_as_null(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', [['show HEAD:missing.txt', $this->fail128()]]);

        $this->assertNull($git->readFromHead('missing.txt'));
    }

    public function test_a_token_is_never_sent_to_a_plain_http_clone_url(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', []);

        try {
            $git->clone('http://github.com/org/repo.git', 'main', 'pat-secret');
            $this->fail('A token went to a plain http URL.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('HTTPS repository URL', $e->getMessage());
        }
        $this->assertSame([], $this->commands);
    }

    public function test_adopting_a_detached_checkout_without_a_branch_is_refused(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['branch --show-current', ''],
        ]);

        $this->expectExceptionObject(new GitException('Could not determine current branch after clone.', 422));
        $git->adoptCheckout('https://github.com/org/repo.git', null, null);
    }

    public function test_branches_skip_a_ref_without_a_name_and_list_any_other_by_its_short_name(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['for-each-ref', implode("\n", [
                'refs/heads/' . self::SEP . self::SEP . self::SEP,
                'refs/heads/main' . self::SEP . 'main' . self::SEP . self::SEP . '*',
                'refs/other/v1' . self::SEP . 'v1' . self::SEP . self::SEP,
            ])],
        ]);

        $this->assertSame(['main', 'v1'], array_column($git->branches(), 'name'));
    }

    public function test_a_deploy_checkout_without_git_is_adopted_again_from_the_deploy_settings(): void
    {
        $model = $this->deployUser();
        $git = $this->git($model, 'project', [
            ['rev-parse --is-inside-work-tree', $this->fail128()],
            ['ls-remote --heads https://github.com/org/repo.git', "abc123\trefs/heads/main\n"],
        ]);

        $status = $git->connect('', '', null);

        $this->assertFalse($status['repository_exists']);
        $this->assertTrue($this->ran('init'));
        $this->assertTrue($this->ran('symbolic-ref HEAD refs/heads/main'));
        $this->assertTrue($this->ran('remote add origin https://github.com/org/repo.git'));
        $this->assertSame('main', $model->getSiteGit('project')['branch']);
        $this->assertSame('deploy-secret', $model->getSiteGit('project')['token']);
    }

    public function test_a_deploy_checkout_connected_without_arguments_keeps_the_deploys_remote(): void
    {
        $model = $this->deployUser();
        $tokens = [];
        $git = $this->git($model, 'project', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['ls-remote --heads https://github.com/org/repo.git', "abc123\trefs/heads/main\n"],
            ['config --get remote.origin.url', "https://github.com/org/repo.git\n"],
            ['branch --show-current', "main\n"],
        ], $tokens);

        $status = $git->connect('', '', null);

        $this->assertTrue($status['connected']);
        $this->assertTrue($this->ran('remote set-url origin https://github.com/org/repo.git'));
        $this->assertSame('deploy-secret', $tokens['ls-remote']);
        $this->assertSame('https://github.com/org/repo.git', $model->getSiteGit('project')['repo_url']);
    }

    public function test_a_deploy_checkout_with_no_branch_anywhere_is_refused(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->details = ['git_repo' => 'https://github.com/org/repo.git', 'template' => 'dind'];
        $git = $this->git($model, 'project', [['rev-parse --is-inside-work-tree', "true\n"]]);

        $this->expectExceptionObject(new GitException('Git is managed by deploy.', 422));
        $git->connect('', '', null);
    }

    public function test_a_remote_that_cannot_be_reached_is_named_and_nothing_is_connected(): void
    {
        $model = $this->plainUser();
        $git = $this->git($model, 'public_html', [
            ['ls-remote', new GitException("fatal: unable to access 'https://github.com/org/repo.git/': Could not resolve host: github.com", 400)],
        ]);

        try {
            $git->connect('https://github.com/org/repo.git', 'main', null);
            $this->fail('An unreachable remote was connected.');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('git_repository_unreachable', $e->problemCode);
            $this->assertStringContainsString('Could not reach github.com', $e->getMessage());
        }
        $this->assertNull($model->getSiteGit('public_html'));
    }

    public function test_a_token_the_host_turns_down_is_named_as_refused(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', [
            ['ls-remote', new GitException("remote: Invalid username or token.\nfatal: Authentication failed for 'https://github.com/org/repo.git/'", 400)],
        ]);

        try {
            $git->connect('https://github.com/org/repo.git', 'main', 'pat-wrong');
            $this->fail('A refused token was connected.');
        } catch (GitException $e) {
            $this->assertSame('git_token_refused', $e->problemCode);
            $this->assertStringContainsString('github.com refused the token', $e->getMessage());
        }
    }

    public function test_a_status_fetch_with_no_stored_branch_fetches_origin_as_configured(): void
    {
        $model = $this->connectedUser(['branch' => '']);
        $git = $this->git($model, 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['branch --show-current', "main\n"],
        ]);

        $git->status(true);

        $fetches = array_values(array_filter($this->commands, fn (string $c): bool => str_contains($c, ' fetch ')));
        $this->assertCount(1, $fetches);
        $this->assertStringEndsWith('fetch.recurseSubmodules=false fetch origin', $fetches[0]);
    }

    public function test_a_fresh_connect_stays_connected_when_its_marker_cannot_be_cleared(): void
    {
        $model = $this->plainUser();
        $git = $this->git($model, 'public_html', [
            ['rev-parse --is-inside-work-tree', $this->fail128()],
            ['ls-remote', "abc123\trefs/heads/main\n"],
            ['config --unset panelalpha.connecting', new GitException('error: could not lock config file', 400)],
        ]);

        $git->connect('https://github.com/org/repo.git', 'main', null);

        $this->assertTrue($this->ran('fetch --depth=1 origin main'));
        $this->assertTrue($this->ran('config --unset panelalpha.connecting'));
        $this->assertSame('main', $model->getSiteGit('public_html')['branch']);
    }

    public function test_disconnect_without_an_origin_removes_no_remote(): void
    {
        $model = $this->connectedUser();
        $git = $this->git($model, 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['config --get remote.origin.url', ''],
        ]);

        $this->assertFalse($git->disconnect()['connected']);
        $this->assertFalse($this->ran('remote remove origin'));
        $this->assertNull($model->getSiteGit('public_html'));
    }

    public function test_disconnect_forgets_the_connection_when_origin_cannot_be_removed(): void
    {
        $model = $this->connectedUser();
        $git = $this->git($model, 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['config --get remote.origin.url', "https://github.com/org/repo.git\n"],
            ['remote remove origin', new GitException('error: could not lock config file', 400)],
        ]);

        $git->disconnect();

        $this->assertTrue($this->ran('remote remove origin'));
        $this->assertNull($model->getSiteGit('public_html'));
    }

    public function test_a_branch_the_remote_lacks_is_named_even_when_its_branches_cannot_be_listed(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->changeBranchRules([
            ['+refs/heads/feature', new GitException("fatal: couldn't find remote ref refs/heads/feature", 400)],
            ['ls-remote --heads origin', new GitException('fatal: unable to access remote', 400)],
        ]));

        try {
            $git->changeBranch('feature');
            $this->fail('A missing branch was checked out.');
        } catch (GitException $e) {
            $this->assertSame('git_branch_not_found', $e->problemCode);
            $this->assertSame("The remote has no branch named 'feature'.", $e->getMessage());
        }
    }

    public function test_a_branch_fetch_that_fails_otherwise_keeps_gits_words(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->changeBranchRules([
            ['+refs/heads/feature', new GitException('fatal: the remote end hung up unexpectedly', 400)],
        ]));

        $this->expectExceptionObject(new GitException('fatal: the remote end hung up unexpectedly', 400));
        $git->changeBranch('feature');
    }

    public function test_a_branch_fetched_but_not_on_origin_is_refused(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->changeBranchRules([
            ['rev-parse --verify --end-of-options origin/feature', $this->fail128()],
        ]));

        $this->expectExceptionObject(new GitException('Branch does not exist on remote.', 422));
        $git->changeBranch('feature');
    }

    public function test_a_branch_change_adds_the_branch_to_the_fetch_and_survives_a_failed_upstream(): void
    {
        $model = $this->connectedUser();
        $git = $this->git($model, 'public_html', $this->changeBranchRules([
            ['config --get-all remote.origin.fetch', new GitException('', 400)],
            ['branch --set-upstream-to=origin/feature', new GitException('fatal: no such branch', 400)],
        ]));

        $git->changeBranch('feature');

        $this->assertTrue($this->ran('remote set-branches --add origin feature'));
        $this->assertTrue($this->ran('checkout -B feature origin/feature'));
        $this->assertSame('feature', $model->getSiteGit('public_html')['branch']);
    }

    public function test_an_unknown_pull_strategy_is_refused(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', [['rev-parse --is-inside-work-tree', "true\n"]]);

        $this->expectExceptionObject(new GitException('Unknown pull strategy.', 422));
        $git->pull('sideways');
    }

    public function test_a_pull_with_no_connected_branch_is_refused(): void
    {
        $git = $this->git($this->connectedUser(['branch' => '']), 'public_html', [['rev-parse --is-inside-work-tree', "true\n"]]);

        $this->expectExceptionObject(new GitException('Connected branch is empty.', 422));
        $git->pull();
    }

    public function test_a_refused_fast_forward_to_a_branch_origin_lacks_says_so(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['merge --ff-only origin/main', new GitException('merge: origin/main - not something we can merge', 400)],
            ['rev-parse --verify --end-of-options origin/main', $this->fail128()],
        ]);

        $this->expectExceptionObject(new GitException('Branch does not exist on remote.', 422));
        $git->pull();
    }

    public function test_connecting_a_detached_checkout_sets_no_tracking(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', $this->connectExistingRules([
            ['branch --show-current', ''],
        ]));

        $git->connect('https://github.com/org/repo.git', 'main', null);

        $this->assertTrue($this->ran('remote add origin https://github.com/org/repo.git'));
        $this->assertFalse($this->ran('config branch.main.remote origin'));
    }

    public function test_connecting_a_branch_that_already_tracks_keeps_its_tracking(): void
    {
        $git = $this->git($this->plainUser(), 'public_html', $this->connectExistingRules([
            ['branch --show-current', "main\n"],
            ['config --get branch.main.merge', "refs/heads/main\n"],
        ]));

        $git->connect('https://github.com/org/repo.git', 'main', null);

        $this->assertFalse($this->ran('config branch.main.remote origin'));
        $this->assertFalse($this->ran('config branch.main.merge refs/heads/main'));
    }

    public function test_a_failed_force_pull_rethrows_when_the_backup_cannot_be_put_back(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['rev-parse --verify HEAD', "abc123\n"],
            ['write-tree', "tree123\n"],
            ['reset --hard origin/main', new GitException('error: unable to unlink old index.php', 400)],
            ['reset --keep abc123', new GitException('error: Entry not uptodate', 400)],
        ]);

        try {
            $git->pull('force');
            $this->fail('A failed reset was reported as a pull.');
        } catch (GitException $e) {
            $this->assertSame('error: unable to unlink old index.php', $e->getMessage());
        }
        $this->assertTrue($this->ran('reset --keep abc123'));
    }

    public function test_a_merge_of_unrelated_histories_is_aborted_and_named(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->pushFirstRules([
            ['merge origin/main', new GitException('fatal: refusing to merge unrelated histories', 400)],
        ]));

        try {
            $git->pull('push_first');
            $this->fail('Unrelated histories were merged.');
        } catch (GitException $e) {
            $this->assertSame('Unrelated git histories.', $e->getMessage());
        }
        $this->assertTrue($this->ran('merge --abort'));
    }

    public function test_a_failed_merge_whose_paths_cannot_be_listed_keeps_gits_words(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->pushFirstRules([
            ['merge origin/main', new GitException('error: merge failed', 400)],
            ['diff --name-only --diff-filter=U', new GitException('fatal: index file corrupt', 400)],
            ['merge --abort', new GitException('fatal: There is no merge to abort', 400)],
        ]));

        $this->expectExceptionObject(new GitException('error: merge failed', 400));
        $git->pull('push_first');
    }

    public function test_a_push_without_a_configured_identity_commits_as_the_account(): void
    {
        $git = $this->git($this->connectedUser(), 'public_html', $this->pushRules());

        $git->push();

        $this->assertTrue($this->ran('-c user.name=alice -c user.email=alice@example.com commit -m Sync from PanelAlpha'));
        $this->assertTrue($this->ran('push origin HEAD:main'));
    }

    public function test_a_push_without_identity_or_domain_commits_at_localhost(): void
    {
        $model = $this->connectedUser();
        $model->domain = null;
        $git = $this->git($model, 'public_html', $this->pushRules());

        $git->push();

        $this->assertTrue($this->ran('-c user.name=alice -c user.email=alice@localhost commit'));
    }

    public function test_a_repair_with_an_empty_stored_connection_is_refused(): void
    {
        $git = $this->git($this->connectedUser(['repo_url' => '', 'branch' => '']), 'public_html', [
            ['rev-parse --is-inside-work-tree', $this->fail128()],
        ]);

        $this->expectExceptionObject(new GitException('Git is not connected.', 422));
        $git->connect('', '', null, true);
    }

    public function test_a_repair_whose_init_fails_stores_nothing(): void
    {
        $model = $this->connectedUser(['branch' => 'develop']);
        $git = $this->git($model, 'public_html', [
            ['rev-parse --is-inside-work-tree', $this->fail128()],
            ['ls-remote', "abc123\trefs/heads/main\n"],
            [' init', new GitException('fatal: cannot mkdir .git: Permission denied', 400)],
        ]);

        try {
            $git->connect('', 'main', null, true);
            $this->fail('A failed init was reported as a repair.');
        } catch (GitException $e) {
            $this->assertStringContainsString('cannot mkdir .git', $e->getMessage());
        }
        $this->assertSame('develop', $model->getSiteGit('public_html')['branch']);
    }

    /**
     * @param  list<array{0: string, 1: string|\Throwable}>  $rules  first needle in the joined argv wins; '' otherwise
     * @param  array<string, ?string>  $tokens  the token each subcommand was last sent with
     */
    private function git(ModelsUser $model, string $path, array $rules, array &$tokens = []): TestableProjectGit
    {
        $execute = function (array $argv, ?string $token) use ($rules, &$tokens): string {
            $joined = implode(' ', $argv);
            $this->commands[] = $joined;
            foreach (['ls-remote', 'fetch', 'push'] as $sub) {
                if (in_array($sub, $argv, true)) {
                    $tokens[$sub] = $token;
                }
            }
            foreach ($rules as [$needle, $answer]) {
                if (str_contains($joined, $needle)) {
                    if ($answer instanceof \Throwable) {
                        throw $answer;
                    }

                    return $answer;
                }
            }

            return '';
        };

        return new TestableProjectGit(new Project(new System(), $model), $path, $execute);
    }

    /** @param list<array{0: string, 1: string|\Throwable}> $first */
    private function changeBranchRules(array $first): array
    {
        return [
            ...$first,
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['status --porcelain', ''],
            ['config --get-all remote.origin.fetch', "+refs/heads/main:refs/remotes/origin/main\n"],
            ['branch --show-current', "feature\n"],
        ];
    }

    /** @param list<array{0: string, 1: string|\Throwable}> $first */
    private function connectExistingRules(array $first): array
    {
        return [
            ...$first,
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['ls-remote', "abc123\trefs/heads/main\n"],
            ['config --get remote.origin.url', ''],
            ['rev-parse --verify HEAD', "abc123\n"],
        ];
    }

    /** @param list<array{0: string, 1: string|\Throwable}> $first */
    private function pushFirstRules(array $first): array
    {
        return [
            ...$first,
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['rev-parse --verify HEAD', "abc123\n"],
            ['write-tree', "tree123\n"],
            ['status --porcelain', ''],
            ['rev-parse --abbrev-ref @{upstream}', "origin/main\n"],
            ['rev-list --count HEAD..@{upstream}', "2\n"],
            ['rev-list --count @{upstream}..HEAD', "0\n"],
        ];
    }

    private function pushRules(): array
    {
        return [
            ['rev-parse --is-inside-work-tree', "true\n"],
            ['rev-parse --verify HEAD', "abc123\n"],
            ['write-tree', "tree123\n"],
            ['status --porcelain', " M index.php\n"],
            ['rev-parse --abbrev-ref @{upstream}', "origin/main\n"],
            ['rev-list --count HEAD..@{upstream}', "0\n"],
            ['rev-list --count @{upstream}..HEAD', "0\n"],
            ['diff --cached --name-only', "index.php\n"],
            ['config --get user.name', ''],
        ];
    }

    private function fail128(): GitException
    {
        return new GitException('fatal: not a git repository (or any of the parent directories): .git', 400);
    }

    private function ran(string $needle): bool
    {
        foreach ($this->commands as $command) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function plainUser(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->domain = 'example.com';

        return $model;
    }

    /** @param array<string, string> $overrides */
    private function connectedUser(array $overrides = []): ModelsUser
    {
        $model = $this->plainUser();
        $model->details = ['site_git' => ['public_html' => array_merge([
            'repo_url' => 'https://github.com/org/repo.git',
            'branch' => 'main',
            'token' => 'pat-secret',
        ], $overrides)]];

        return $model;
    }

    private function deployUser(): ModelsUser
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
}
